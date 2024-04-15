<?php

namespace Symbiote\GridFieldExtensions;

use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldPageCount;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

class GridFieldNestedForm_ItemRequest extends GridFieldDetailForm_ItemRequest
{
    
    public function Link($action = null)
    {
        return Controller::join_links($this->component->Link($this->record->ID), $action);
    }
    
    public function ItemEditForm()
    {
        $config = new GridFieldConfig_RecordEditor();
        $relationName = $this->component->getRelationName();
        $list = $this->record->$relationName();
        if ($relationName == 'Children' && $this->record->hasExtension(Hierarchy::class)) {
            // we really need a HasManyList for Hierarchy objects, otherwise adding new items will not properly set the ParentID
            $list = HasManyList::create(get_class($this->record), 'ParentID')
                        ->setDataQueryParam($this->record->getInheritableQueryParams())
                        ->forForeignID($this->record->ID);
        }
        $relationClass = $list->dataClass();

        if ($this->record->hasMethod('getNestedConfig')) {
            $config = $this->record->getNestedConfig();
        } else {
            $canEdit = $this->record->canEdit();
            if (!$canEdit) {
                $config->removeComponentsByType(GridFieldAddNewButton::class);
            }
            $config->removeComponentsByType(GridFieldPageCount::class);
            if ($relationClass == get_class($this->record)) {
                $config->removeComponentsByType(GridFieldSortableHeader::class);
                $config->removeComponentsByType(GridFieldFilterHeader::class);
            }

            if ($this->record->hasExtension(Hierarchy::class)) {
                $config->addComponent(new GridFieldNestedForm(), GridFieldOrderableRows::class);
                /** @var GridFieldOrderableRows */
                $orderableRows = $config->getComponentByType(GridFieldOrderableRows::class);
                if ($orderableRows) {
                    $orderableRows->setReorderColumnNumber(1);
                }
            }

            if ($this->component->getInlineEditable() && $canEdit) {
                $config->removeComponentsByType(GridFieldDataColumns::class);
                $config->addComponent(new GridFieldEditableColumns(), GridFieldEditButton::class);
                $config->addComponent(new GridFieldAddNewInlineButton('buttons-before-left'));
                $config->removeComponentsByType(GridFieldAddNewButton::class);
                /** @var GridFieldNestedForm */
                $nestedForm = $config->getComponentByType(GridFieldNestedForm::class);
                if ($nestedForm) {
                    $nestedForm->setInlineEditable(true);
                }
            }
        }

        $this->record->invokeWithExtensions('updateNestedConfig', $config);

        $title = _t(get_class($this->record).'.'.strtoupper($relationName), ' ');
        
        $fields = new FieldList(
            $gridField = new GridField($this->component->getGridField()->getName().'['.GridFieldNestedForm::POST_KEY.']['.$this->record->ID.']', $title, $list, $config)
        );
        if (!trim($title)) {
            $gridField->addExtraClass('empty-title');
        }
        $gridField->setModelClass($relationClass);
        $gridField->setAttribute('data-class', str_replace('\\', '-', $relationClass));
        $gridField->addExtraClass('nested');
        $form = new Form($this, 'ItemEditForm', $fields, new FieldList());
        $form->setStrictFormMethodCheck(false);
        $form->disableSecurityToken();

        $className = str_replace('\\', '-', get_class($this->record));
        $state = $this->gridField->getState()->GridFieldNestedForm;
        if ($state) {
            $stateRelation = $className.'-'.$this->record->ID.'-'.$relationName;
            $state->$stateRelation = 1;
        }

        $this->record->extend('updateNestedForm', $form);
        return $form;
    }
    
    public function Breadcrumbs($unlinked = false)
    {
        if (!$this->popupController->hasMethod('Breadcrumbs')) {
            return null;
        }
        
        /** @var ArrayList $items */
        $items = $this->popupController->Breadcrumbs($unlinked);
        
        $this->extend('updateBreadcrumbs', $items);
        return $items;
    }
}
