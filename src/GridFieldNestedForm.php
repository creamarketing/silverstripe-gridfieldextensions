<?php

namespace Symbiote\GridFieldExtensions;

use Exception;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_SaveHandler;
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
class GridFieldNestedForm extends GridFieldDetailForm implements
    GridField_ColumnProvider,
    GridField_SaveHandler,
    GridField_HTMLProvider,
    GridField_DataManipulator
{
    
    const POST_KEY = 'GridFieldNestedForm';

    private static $allowed_actions = [
        'handleNestedItem'
    ];
    
    protected $expandNested = false;
    protected $forceCloseNested = false;
    protected $gridField = null;
    protected $record = null;
    protected $relationName = 'Children';
    protected $inlineEditable = false;
    protected $canExpandCheck = null;
    
    public function __construct($name = 'NestedForm')
    {
        $this->name = $name;
    }
    
    public function getGridField()
    {
        return $this->gridField;
    }

    public function getRelationName()
    {
        return $this->relationName;
    }
    
    public function setRelationName($relationName)
    {
        $this->relationName = $relationName;
        return $this;
    }

    public function getInlineEditable()
    {
        return $this->inlineEditable;
    }

    public function setInlineEditable($editable)
    {
        $this->inlineEditable = $editable;
        return $this;
    }
    
    public function setExpandNested($expandNested)
    {
        $this->expandNested = $expandNested;
        return $this;
    }

    public function setForceClosedNested($forceClosed)
    {
        $this->forceCloseNested = $forceClosed;
        return $this;
    }

    public function setCanExpandCheck($checkFunction)
    {
        $this->canExpandCheck = $checkFunction;
        return $this;
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
        $gridField->addExtraClass('has-nested');
        if ($record->ID && $record->exists()) {
            $this->gridField = $gridField;
            $this->record = $record;
            $relationName = $this->getRelationName();
            if (!$record->hasMethod($relationName)) {
                return '';
            }
            if ($this->canExpandCheck) {
                if (is_callable($this->canExpandCheck) && !call_user_func($this->canExpandCheck, $record)) {
                    return '';
                } elseif (is_string($this->canExpandCheck) && $record->hasMethod($this->canExpandCheck) && !$this->record->{$this->canExpandCheck}($record)) {
                    return '';
                }
            }
            $toggle = 'closed';
            $className = str_replace('\\', '-', get_class($record));
            $state = $gridField->State->GridFieldNestedForm;
            $stateRelation = $className.'-'.$record->ID.'-'.$this->relationName;
            if (!$this->forceCloseNested && (($this->expandNested && $record->$relationName()->count() > 0) || ($state && (int)$state->getData($stateRelation) === 1))) {
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
        $to = isset($move['parent']) ? (int)$move['parent'] : null;
        $parent = null;
        if ($id) {
            // should be possible either on parent or child grid field, or nested grid field from parent
            $parent = $to ? $list->byID($to) : null;
            if (!$parent && $to && $gridField->getForm()->getController() instanceof GridFieldNestedForm_ItemRequest && $gridField->getForm()->getController()->getRecord()->ID == $to) {
                $parent = $gridField->getForm()->getController()->getRecord();
            }
            $child = $list->byID($id);
            if ($parent || $child || $to === 0) {
                if (!$parent && $to) {
                    $parent = DataList::create($gridField->getModelClass())->byID($to);
                }
                if (!$child) {
                    $child = DataList::create($gridField->getModelClass())->byID($id);
                }
                if ($child) {
                    if ($child->hasExtension(Hierarchy::class)) {
                        $child->ParentID = $parent ? $parent->ID : 0;
                    }
                    $validationResult = $child->validate();
                    if ($validationResult->isValid()) {
                        if ($child->hasExtension(Versioned::class)) {
                            $child->writeToStage(Versioned::DRAFT);
                        } else {
                            $child->write();
                        }

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
            }
        }
        return $gridField->FieldHolder();
    }
    
    public function handleNestedItem(GridField $gridField, $request = null, $record = null)
    {
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
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $request ?: $gridField->getForm()->getRequestHandler()->getRequest())) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        $this->gridField = $gridField;
        $this->record = $record;
        $itemRequest = new GridFieldNestedForm_ItemRequest($gridField, $this, $record, $gridField->getForm()->getController(), $this->name);
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
        $value = $gridField->Value();
        if (!isset($value[self::POST_KEY]) || !is_array($value[self::POST_KEY])) {
            return;
        }

        if (isset($value['GridState']) && $value['GridState']) {
            // set grid state from value, to store open/closed toggle state for nested forms
            $gridField->getState(false)->setValue($value['GridState']);
        }
        $manager = $this->getStateManager();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $gridField->getForm()->getRequestHandler()->getRequest())) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        foreach ($value[self::POST_KEY] as $recordID => $nestedData) {
            $record = $gridField->getList()->byID($recordID);
            if ($record) {
                $nestedGridField = $this->handleNestedItem($gridField, null, $record);
                $nestedGridField->setValue($nestedData);
                $nestedGridField->saveInto($record);
            }
        }
    }

    public function getManipulatedData(GridField $gridField, SS_List $dataList)
    {
        if ($this->relationName == 'Children' && DataObject::has_extension($gridField->getModelClass(), Hierarchy::class) && $gridField->getForm()->getController() instanceof ModelAdmin) {
            $dataList = $dataList->filter('ParentID', 0);
        }
        return $dataList;
    }
}
