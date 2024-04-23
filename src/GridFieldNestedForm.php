<?php

namespace Symbiote\GridFieldExtensions;

use Exception;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_SaveHandler;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\GridField\GridFieldStateAware;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\ORM\SS_List;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ViewableData;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * Gridfield component for nesting GridFields
 */
class GridFieldNestedForm extends AbstractGridFieldComponent implements
    GridField_URLHandler,
    GridField_ColumnProvider,
    GridField_SaveHandler,
    GridField_HTMLProvider,
    GridField_DataManipulator
{
    use Configurable, GridFieldStateAware;
    
    const POST_KEY = 'GridFieldNestedForm';

    private static $allowed_actions = [
        'handleNestedItem'
    ];

    private static $max_nesting_level = 10;
    
    /**
     * @var string
     */
    protected $name;
    protected $expandNested = false;
    protected $forceCloseNested = false;
    protected $gridField = null;
    protected $record = null;
    protected $relationName = 'Children';
    protected $inlineEditable = false;
    protected $canExpandCheck = null;
    protected $maxNestingLevel = null;
    
    public function __construct($name = 'NestedForm')
    {
        $this->name = $name;
    }
    
    /**
     * Get the grid field that this component is attached to
     * @return GridField
     */
    public function getGridField()
    {
        return $this->gridField;
    }

    /**
     * Get the relation name to use for the nested grid fields
     * @return string
     */
    public function getRelationName()
    {
        return $this->relationName;
    }
    
    /**
     * Set the relation name to use for the nested grid fields
     * @param string $relationName
     */
    public function setRelationName($relationName)
    {
        $this->relationName = $relationName;
        return $this;
    }

    /**
     * Get whether the nested grid fields should be inline editable
     * @return boolean
     */
    public function getInlineEditable()
    {
        return $this->inlineEditable;
    }

    /**
     * Set whether the nested grid fields should be inline editable
     * @param boolean $editable
     */
    public function setInlineEditable($editable)
    {
        $this->inlineEditable = $editable;
        return $this;
    }
    
    /**
     * Set whether the nested grid fields should be expanded by default
     * @param boolean $expandNested
     */
    public function setExpandNested($expandNested)
    {
        $this->expandNested = $expandNested;
        return $this;
    }

    /**
     * Set whether the nested grid fields should be forced closed on load
     * @param boolean $forceClosed
     */
    public function setForceClosedNested($forceClosed)
    {
        $this->forceCloseNested = $forceClosed;
        return $this;
    }

    /**
     * Set a callback to check which items in this grid that should show the expand link
     * for nested gridfields. The callback should return a boolean value.
     * @param callable $checkFunction
     */
    public function setCanExpandCheck($checkFunction)
    {
        $this->canExpandCheck = $checkFunction;
        return $this;
    }

    /**
     * Set the maximum nesting level allowed for nested grid fields
     * @param int $level
     */
    public function setMaxNestingLevel($level)
    {
        $this->maxNestingLevel = $level;
        return $this;
    }

    public function getMaxNestingLevel()
    {
        return $this->maxNestingLevel ?: $this->config()->max_nesting_level;
    }

    protected function getNestingLevel($gridField)
    {
        $level = 0;
        $c = $gridField->getForm()->getController();
        while ($c && $c instanceof GridFieldDetailForm_ItemRequest) {
            $c = $c->getController();
            $level++;
        }
        return $level;
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => ''];
    }

    public function getColumnsHandled($gridField)
    {
        return ['ToggleNested'];
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'col-listChildrenLink grid-field__col-compact'];
    }

    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('ToggleNested', $columns)) {
            array_splice($columns, 0, 0, 'ToggleNested');
        }
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        $nestingLevel = $this->getNestingLevel($gridField);
        if ($nestingLevel >= $this->getMaxNestingLevel()) {
            return '';
        }
        $gridField->addExtraClass('has-nested');
        if ($record->ID && $record->exists()) {
            $this->gridField = $gridField;
            $this->record = $record;
            $relationName = $this->getRelationName();
            if (!$record->hasMethod($relationName)) {
                return '';
            }
            if ($this->canExpandCheck) {
                if (is_callable($this->canExpandCheck)
                    && !call_user_func($this->canExpandCheck, $record)
                ) {
                    return '';
                } elseif (is_string($this->canExpandCheck)
                    && $record->hasMethod($this->canExpandCheck)
                    && !$this->record->{$this->canExpandCheck}($record)
                ) {
                    return '';
                }
            }
            $toggle = 'closed';
            $className = str_replace('\\', '-', get_class($record));
            $state = $gridField->State->GridFieldNestedForm;
            $stateRelation = $className.'-'.$record->ID.'-'.$this->relationName;
            $openState = $state && (int)$state->getData($stateRelation) === 1;
            $forceExpand = $this->expandNested && $record->$relationName()->count() > 0;
            if (!$this->forceCloseNested
                && ($forceExpand || $openState)
            ) {
                $toggle = 'open';
            }

            GridFieldExtensions::include_requirements();

            return ViewableData::create()->customise([
                'Toggle' => $toggle,
                'Link' => $this->Link($record->ID),
                'ToggleLink' => $this->ToggleLink($record->ID),
                'PjaxFragment' => $stateRelation,
                'NestedField' => ($toggle == 'open') ? $this->handleNestedItem($gridField, null, $record): ' '
            ])->renderWith('Symbiote\GridFieldExtensions\GridFieldNestedForm');
        }
    }
    
    public function getURLHandlers($gridField)
    {
        return [
            'nested/$RecordID/$NestedAction' => 'handleNestedItem',
            'toggle/$RecordID' => 'toggleNestedItem',
            'POST movetoparent' => 'handleMoveToParent'
        ];
    }

    /**
     * @param GridField $field
     */
    public function getHTMLFragments($field)
    {
        if (DataObject::has_extension($field->getModelClass(), Hierarchy::class)) {
            $field->setAttribute('data-url-movetoparent', $field->Link('movetoparent'));
        }
    }

    public function handleMoveToParent(GridField $gridField, $request)
    {
        $move = $request->postVar('move');
        /** @var DataList */
        $list = $gridField->getList();
        $id = isset($move['id']) ? (int) $move['id'] : null;
        if (!$id) {
            throw new HTTPResponse_Exception('Missing ID', 400);
        }
        $to = isset($move['parent']) ? (int)$move['parent'] : null;
        // should be possible either on parent or child grid field, or nested grid field from parent
        $parent = $to ? $list->byID($to) : null;
        if (!$parent
            && $to
            && $gridField->getForm()->getController() instanceof GridFieldNestedFormItemRequest
            && $gridField->getForm()->getController()->getRecord()->ID == $to
        ) {
            $parent = $gridField->getForm()->getController()->getRecord();
        }
        $child = $list->byID($id);
        // we need either a parent or a child, or a move to top level at this stage
        if (!($parent || $child || $to === 0)) {
            throw new HTTPResponse_Exception('Invalid request', 400);
        }
        // parent or child might be from another grid field, so we need to search via DataList in some cases
        if (!$parent && $to) {
            $parent = DataList::create($gridField->getModelClass())->byID($to);
        }
        if (!$child) {
            $child = DataList::create($gridField->getModelClass())->byID($id);
        }
        if ($child) {
            if (!$child->canEdit()) {
                throw new HTTPResponse_Exception('Not allowed', 403);
            }
            if ($child->hasExtension(Hierarchy::class)) {
                $child->ParentID = $parent ? $parent->ID : 0;
            }
            // validate that the record is still valid
            $validationResult = $child->validate();
            if ($validationResult->isValid()) {
                if ($child->hasExtension(Versioned::class)) {
                    $child->writeToStage(Versioned::DRAFT);
                } else {
                    $child->write();
                }

                // reorder items at the same time, if applicable
                /** @var GridFieldOrderableRows */
                $orderableRows = $gridField->getConfig()->getComponentByType(GridFieldOrderableRows::class);
                if ($orderableRows) {
                    $orderableRows->setImmediateUpdate(true);
                    try {
                        $orderableRows->handleReorder($gridField, $request);
                    } catch (Exception $e) {
                    }
                }
            } else {
                $messages = $validationResult->getMessages();
                $message = array_pop($messages);
                throw new HTTPResponse_Exception($message['message'], 400);
            }
        }
        return $gridField->FieldHolder();
    }
    
    public function handleNestedItem(GridField $gridField, $request = null, $record = null)
    {
        $nestingLevel = $this->getNestingLevel($gridField);
        if ($nestingLevel >= $this->getMaxNestingLevel()) {
            throw new Exception('Max nesting level reached');
        }
        if (!$record && $request) {
            $recordID = $request->param('RecordID');
            $record = $gridField->getList()->byID($recordID);
        }
        if (!$record) {
            return '';
        }
        $relationName = $this->getRelationName();
        if (!$record->hasMethod($relationName)) {
            return '';
        }
        $manager = $this->getStateManager();
        $stateRequest = $request ?: $gridField->getForm()->getRequestHandler()->getRequest();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $stateRequest)) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        $this->gridField = $gridField;
        $this->record = $record;
        $itemRequest = GridFieldNestedFormItemRequest::create(
            $gridField,
            $this,
            $record,
            $gridField->getForm()->getController(),
            $this->name
        );
        if ($request) {
            $pjaxFragment = $request->getHeader('X-Pjax');
            $targetPjaxFragment = str_replace('\\', '-', get_class($record)).'-'.$record->ID.'-'.$this->relationName;
            if ($pjaxFragment == $targetPjaxFragment) {
                $pjaxReturn = [$pjaxFragment => $itemRequest->ItemEditForm()->Fields()->first()->forTemplate()];
                $response = new HTTPResponse(json_encode($pjaxReturn));
                $response->addHeader('Content-Type', 'text/json');
                return $response;
            } else {
                return $itemRequest->ItemEditForm();
            }
        } else {
            return $itemRequest->ItemEditForm()->Fields()->first();
        }
    }

    public function toggleNestedItem(GridField $gridField, $request = null, $record = null)
    {
        if (!$record) {
            $recordID = $request->param('RecordID');
            $record = $gridField->getList()->byID($recordID);
        }
        $manager = $this->getStateManager();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $request)) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        $className = str_replace('\\', '-', get_class($record));
        $state = $gridField->getState()->GridFieldNestedForm;
        $stateRelation = $className.'-'.$record->ID.'-'.$this->getRelationName();
        $state->$stateRelation = (int)$request->getVar('toggle');
    }
    
    public function Link($action = null)
    {
        $link = Director::absoluteURL(Controller::join_links($this->gridField->Link('nested'), $action));
        $manager = $this->getStateManager();
        return $manager->addStateToURL($this->gridField, $link);
    }

    public function ToggleLink($action = null)
    {
        $link = Director::absoluteURL(Controller::join_links($this->gridField->Link('toggle'), $action, '?toggle='));
        $manager = $this->getStateManager();
        return $manager->addStateToURL($this->gridField, $link);
    }
    
    public function handleSave(GridField $gridField, DataObjectInterface $record)
    {
        $postKey = self::POST_KEY;
        $value = $gridField->Value();
        if (isset($value['GridState']) && $value['GridState']) {
            // set grid state from value, to store open/closed toggle state for nested forms
            $gridField->getState(false)->setValue($value['GridState']);
        }
        $manager = $this->getStateManager();
        $request = $gridField->getForm()->getRequestHandler()->getRequest();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $request)) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        foreach ($request->postVars() as $key => $val) {
            if (preg_match("/{$gridField->getName()}-{$postKey}-(\d+)/", $key, $matches)) {
                $recordID = $matches[1];
                $nestedData = $val;
                $record = $gridField->getList()->byID($recordID);
                if ($record) {
                    $nestedGridField = $this->handleNestedItem($gridField, null, $record);
                    $nestedGridField->setValue($nestedData);
                    $nestedGridField->saveInto($record);
                }
            }
        }
    }

    public function getManipulatedData(GridField $gridField, SS_List $dataList)
    {
        if ($this->relationName == 'Children'
            && DataObject::has_extension($gridField->getModelClass(), Hierarchy::class)
            && $gridField->getForm()->getController() instanceof ModelAdmin
        ) {
            $dataList = $dataList->filter('ParentID', 0);
        }
        return $dataList;
    }
}
