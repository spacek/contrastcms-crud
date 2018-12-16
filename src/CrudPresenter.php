<?php

namespace ContrastCms\Crud;

use ContrastCms\Application\AdminModule\AdminBasePresenter;
use ContrastCms\Application\AdminModule\SecuredPresenter;
use ContrastCms\Application\FileRepository;
use ContrastCms\VisualPaginator\VisualPaginator;
use Czubehead\BootstrapForms\BootstrapForm;
use Czubehead\BootstrapForms\Enums\RenderMode;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Form;
use Nette\Utils\Strings;

class CrudPresenter extends SecuredPresenter
{
    public $lang = "cs_CZ";

    public $name = "";
    public $table = "";
    public $fields = [];
    public $customActions = [];
    public $sortable = false;
    public $submodules = false;
    public $enableExport = false;
    public $showTotals = false;
    public $enableDeletes = true;

    public $defaultPagination = 30;

    public $listAllHeading = "Výpis položek";
    public $createNewHeading = "Nová položka";
    public $displayId = false;

    /** @persistent */
    public $parent_id = null;

    /** @persistent */
    public $submodule = null;

    public function startup()
    {
        parent::startup();

        $this->template->listAllHeading = $this->template->defaultListAllHeading = $this->listAllHeading;
        $this->template->createNewHeading = $this->createNewHeading;
        $this->template->fields = $this->fields;
        $this->template->customActions = $this->customActions;
        $this->template->sortable = $this->sortable;
        $this->template->displayId = $this->displayId;
        $this->template->submodules = $this->submodules;
        $this->template->enableDeletes = $this->enableDeletes;
        $this->template->submodule = $submodule = $this->getParameter("submodule", null);
        $this->template->moduleName = $this->moduleName ?? $this->name;
        if ($submodule) {
            $this->template->listAllHeading = $this->submodules[$submodule]["listAllHeading"];
        }
    }

    protected function getDatabaseSelection($submodule = null)
    {
        if (!$submodule) {
            return $this->context->getService("crudRepository")->getTable($this->table)->where("lang = ?", $this->lang);
        } else {
            return $this->context->getService("crudRepository")->getTable($this->table . "_" . $submodule);
        }

    }


    public function actionDefault()
    {

        $session = $this->context->getService("session");
        $filter = $session->getSection("filter-" . $this->name);
        $filter->limit = $this->defaultPagination;
        $this->template->limit = $filter->limit;

        $selection = $this->getDatabaseSelection();

        $filterEnabled = false;
        $filterableFields = [];
        foreach ($this->fields as $key => $field) {
            if (isset($field["filterable"]) && $field["filterable"]) {
                $filterableFields[$key] = $field;
            }
        }

        if (count($filterableFields) > 0) {
            $filterEnabled = true;
        }

        foreach ($filterableFields as $fieldName => $field) {
            $paramValue = $this->getParameter($fieldName, "");
            if ($paramValue) {
                if ($field["type"] == "text" || $field["type"] == "integer") {
                    $selection->where("$fieldName IS LIKE ?", "%" . $this->getParameter($fieldName, "") . "%");
                } elseif ($field["type"] == "select") {
                    $selection->where("$fieldName = ?", $this->getParameter($fieldName, ""));
                }
            }
        }

        // Pagination
        $vp = new VisualPaginator();
        $vp->loadState($this->request->getParameters());
        $paginator = $vp->getPaginator();
        $paginator->itemsPerPage = $filter->limit;
        $paginator->itemCount = $selection->count();

        $this->template->filterEnabled = $filterEnabled;
        $this->template->filterableFields = $filterableFields;

        if ($this->sortable) {
            $this->template->results = $selection->order("order DESC, id ASC")->limit($paginator->itemsPerPage, $paginator->offset);
        } else {
            $this->template->results = $selection->order("id DESC")->limit($paginator->itemsPerPage, $paginator->offset);
        }

        $this->addComponent($vp, 'vp');

        // View
        $this->template->setFile(__DIR__ . '/templates/default.latte');

    }

    public function actionSubmoduleView($submodule, $parent_id)
    {
        $this->setView("default");

        $module = $this->submodules[$submodule];

        $session = $this->context->getService("session");
        $filter = $session->getSection("filter-" . $this->name);
        $filter->limit = $this->defaultPagination;
        $this->template->limit = $filter->limit;

        $vp = new VisualPaginator();
        $vp->loadState($this->request->getParameters());
        $paginator = $vp->getPaginator();
        $paginator->itemsPerPage = $filter->limit;
        $paginator->itemCount = $this->getDatabaseSelection($submodule)->count();

        if ($module["sortable"]) {
            $this->template->results = $this->getDatabaseSelection($submodule)->where("parent_id = ?", $parent_id)->order("order DESC, id ASC")->limit($paginator->itemsPerPage, $paginator->offset);
        } else {
            $this->template->results = $this->getDatabaseSelection($submodule)->where("parent_id = ?", $parent_id)->limit($paginator->itemsPerPage, $paginator->offset);
        }

        $this->template->listAllHeading = $module["listAllHeading"];
        $this->template->createNewHeading = $module["createNewHeading"];
        $this->template->fields = $module["fields"];
        $this->template->parent_id = $parent_id;
        $this->template->submodule = $submodule;

        $this->addComponent($vp, 'vp');

        // View
        $this->template->setFile(__DIR__ . '/templates/default.latte');
    }

    public function actionUpdate($id, $lang)
    {

        $parent_id = $this->getParameter("parent_id", null);
        $submodule = $this->getParameter("submodule", null);

        $this["entryForm"]["crud_action_type"]->setValue("update");
        $this["entryForm"]["id"]->setValue($id);

        // Load other data


        $record = $this->getDatabaseSelection($submodule)->where("id = ?", $id)->fetch();

        if (!$record) {
            throw new BadRequestException();
        }

        // Populate
        $this['entryForm']->setDefaults($record);

        $this->template->parent_id = $parent_id;

        // View
        $this->template->setFile(__DIR__ . '/templates/create.latte');
    }

    public function actionCreate($id, $lang)
    {
        $this["entryForm"]["crud_action_type"]->setValue("create");
        $this["entryForm"]["id"]->setValue($id);

        // View
        $this->template->setFile(__DIR__ . '/templates/create.latte');
    }

    public function getPrimaryNameField($id, $submodule = null)
    {
        $record = $this->getDatabaseSelection($submodule)->where("id = ?", $id)->fetch();
        $primaryNameField = $this->getPrimaryNameFieldItem($submodule);
        return $record->{$primaryNameField};
    }

    public function getPrimaryNameFieldItem($submodule = null)
    {
        if ($submodule) {
            $fields = $this->submodules[$submodule]["fields"];
        } else {
            $fields = $this->fields;
        }

        foreach ($fields as $key => $item) {
            if ($item["primary_name"] == 1) {
                return $key;
            }
        }

        return "id";
    }

    // Form

    protected function createComponentEntryForm()
    {

        $parent_id = $this->getParameter("parent_id", null);
        $submodule = $this->getParameter("submodule", null);

        if (!$submodule) {
            $fields = $this->fields;
        } else {
            $fields = $this->submodules[$submodule]["fields"];
        }

        $form = new BootstrapForm();
        $form->addHidden("crud_action_type", "create");
        $form->addHidden("id", null);
        $form->addHidden("lang", $this->lang);
        $form->addHidden("submodule", $submodule);
        $form->addHidden("parent_id", $parent_id);

        if ($submodule) {
            $this->template->formHeadingSuffix = $this->getPrimaryNameField($parent_id, null);
        }

        foreach ($fields as $name => $field) {
            switch ($field["type"]) {
                case "text":
                    $item = $form->addText($name, $field["label"])->setRequired($field["required"]);

                    if (isset($field["default_value_date_today"]) && $field["default_value_date_today"]) {
                        $item->setDefaultValue(date("Y-m-d H:00:00"));
                    }

                    break;

                case "datetime":
                    $item = $form->addDateTime($name, $field["label"])->setRequired($field["required"]);
                    $item->setDefaultValue(date("j.n.Y H:00"));
                    break;

                case "integer":
                    $item = $form->addInteger($name, $field["label"])->setRequired($field["required"]);

                    if (isset($field["default_value_date_today"]) && $field["default_value_date_today"]) {
                        $item->setDefaultValue(date("Y-m-d H:00:00"));
                    }

                    break;

                case "upload":

                    $type = $field["upload_type"] ?? "file";
                    $isMultiple = $field["is_multiple"] ?? false;


                    if ($type !== "image") {
                        if (!$isMultiple) {
                            $form->addUpload($name, $field["label"])->setRequired($field["required"]);
                        } else {
                            $form->addMultiUpload($name, $field["label"])->setRequired($field["required"]);
                        }
                    } else {
                        if (!$isMultiple) {
                            $form->addUpload($name, $field["label"])->setRequired($field["required"])->addCondition($form::FILLED)->addRule($form::IMAGE, "Please choose image");
                        } else {
                            $form->addMultiUpload($name, $field["label"])->setRequired($field["required"])->addCondition($form::FILLED)->addRule($form::IMAGE, "Please choose image");
                        }
                    }


                    break;

                case "select":

                    $items = [];

                    if (!$field["required"]) {
                        $items[0] = "- Choose -";
                    }

                    if ($field["items_provider"] === "array") {
                        $items += $field["items_provider_value"];
                    } elseif ($field["items_provider"] === "table") {
                        $items += $this->getTableSelectableValues($field["items_provider_value"]);
                    } elseif ($field["items_provider"] === "function") {
                        $items += $this->{$field["items_provider_value"]}();
                    }

                    $form->addSelect($name, $field["label"], $items);
                    break;

                case "textarea":
                    $item = $form->addTextArea($name, $field["label"])->setRequired($field["required"]);
                    if (isset($field["ckeditor"]) && $field["ckeditor"]) {
                        $item->getControlPrototype()->addAttributes(array("class" => "ckeditor"));
                    }
                    break;
            }
        }

        $form->addSubmit("store", "Submit");

        $form->onValidate[] = [$this, 'validateForm'];
        $form->onSuccess[] = [$this, "processForm"];
        return $form;
    }

    // Filter Form

    protected function createComponentFilterForm()
    {

        $form = new BootstrapForm();
        $form->renderMode = RenderMode::Inline;
        $form->setMethod("GET");
        $form->setAction($this->link("default"));

        foreach ($this->fields as $name => $field) {

            if (!isset($field["filterable"]) || !$field["filterable"]) {
                continue;
            }

            switch ($field["type"]) {
                case "text":
                case "integer":
                    $item = $form->addText($name, $field["label"] . ": ");

                    if (isset($field["default_value_date_today"]) && $field["default_value_date_today"]) {
                        $item->setDefaultValue(date("Y-m-d H:00:00"));
                    }

                    break;

                case "select":

                    $items = [];

                    $items[0] = "- Choose -";

                    if ($field["items_provider"] == "array") {
                        $items += $field["items_provider_value"];
                    } elseif ($field["items_provider"] == "table") {
                        $items += $this->getTableSelectableValues($field["items_provider_value"]);
                    } elseif ($field["items_provider"] == "function") {
                        $items += $this->{$field["items_provider_value"]}();
                    }

                    $form->addSelect($name, $field["label"] . ": ", $items);
                    break;
            }
        }

        $form->addSubmit("store", "Odeslat");
        return $form;
    }

    public function getSelectFieldValue($id, $field)
    {
        $items = [];
        if ($field["items_provider"] == "array") {
            $items += $field["items_provider_value"];
        } elseif ($field["items_provider"] == "table") {
            $items += $this->getTableSelectableValues($field["items_provider_value"]);
        } elseif ($field["items_provider"] == "function") {
            $items += $this->{$field["items_provider_value"]}();
        }

        return (isset($items[$id])) ? $items[$id] : "";

    }

    public function getTableSelectableValues($tableName)
    {
        $rows = $this->context->getService("crudRepository")->getTable($tableName);
        $items = [];
        foreach ($rows as $row) {
            $items[$row->id] = $row->name;
        }

        return $items;
    }

    public function processForm(BootstrapForm $form)
    {
        $values = $form->getValues();
        $submodule = null;
        if (!$values->submodule) {
            unset($values->submodule);
            unset($values->parent_id);
        } else {
            $submodule = $values->submodule;
            $parent_id = $values->parent_id;
            unset($values->lang);
            unset($values->submodule);
        }

        if (!$submodule) {
            $fields = $this->fields;
        } else {
            $fields = $this->submodules[$submodule]["fields"];
        }

        // Pre-flight
        $isBatchOperation = false;
        foreach ($fields as $key => $field) {
            if ($field["type"] === "upload" && isset($field['is_multiple']) && $field['is_multiple'] === true) {
                $isBatchOperation = true;
            }
        }

        if (!$isBatchOperation || $values->crud_action_type === "update") {
            foreach ($fields as $key => $field) {
                if ($field["type"] === "upload") {
                    if ($values->{$key}->isOk()) {

                        $fileType = ($field["upload_type"] === "image") ? "image" : "file";
                        $file_id = $this->context->getService("fileRepository")->storeFile($values->{$key}, $fileType);

                        if ($file_id) {
                            $values->{$key} = $file_id;
                        }

                    } else {
                        unset($values[$key]);
                    }

                }

                if (isset($field["is_slug"]) && $field["is_slug"] == true && $field["type"] == "text") {
                    $values->slug = Strings::webalize($values->{$key});
                }
            }

            if ($values->crud_action_type === "update") {

                // Unset redudant fields
                $id = $values->id;
                unset($values->id);
                unset($values->crud_action_type);

                // Do query
                $result = $this->getDatabaseSelection($submodule)->where("id = ?", $id)->fetch()->update((array)$values);

                if ($result) {
                    $this->fireEvent("updated", $result, $values);
                    $this->flashMessage('Položka byla úspěšně upravena.');
                } else {
                    $this->flashMessage('Položku se nepodařilo upravit, nebo nedošlo k žádné změně.', 'error');
                }

                if (!$submodule) {
                    $this->redirect("default");
                } else {
                    $this->redirect("submoduleView", [
                        "submodule" => $submodule,
                        "parent_id" => $parent_id
                    ]);
                }

            } else {
                // Unset redudant fields
                unset($values->id);
                unset($values->crud_action_type);

                // Do query
                $result = $this->getDatabaseSelection($submodule)->insert((array)$values);

                if ($result) {
                    $this->flashMessage('Položka byla úspěšně přidána.');

                    $this->fireEvent("created", $result, $values);

                    if (!$submodule) {
                        $this->redirect("default");
                    } else {
                        $this->redirect("submoduleView", [
                            "submodule" => $submodule,
                            "parent_id" => $parent_id
                        ]);
                    }
                } else {
                    $this->flashMessage('Položku se nepodařilo přidat.', 'error');
                    $this->redirect("default");
                }

            }
        } else {
            foreach ($fields as $key => $field) {
                if ($field["type"] === "upload") {
                    if ($values->{$key}->isOk()) {

                        foreach ($values->{$key} as $fileUpload) {

                            $values2 = $values;

                            $fileType = ($field["upload_type"] === "image") ? "image" : "file";
                            $file_id = $this->context->getService("fileRepository")->storeFile($fileUpload, $fileType);

                            if ($file_id) {
                                $values2->{$key} = $file_id;
                            }

                            // Unset redudant fields
                            unset($values->id);
                            unset($values->crud_action_type);

                            // Do query
                            $result = $this->getDatabaseSelection($submodule)->insert((array)$values);
                        }

                    } else {
                        unset($values[$key]);
                    }
                }
            }

            if ($result) {
                $this->flashMessage('Položky byly úspěšně přidány.');
                if (!$submodule) {
                    $this->redirect("default");
                } else {
                    $this->redirect("submoduleView", [
                        "submodule" => $submodule,
                        "parent_id" => $parent_id
                    ]);
                }

            } else {
                $this->flashMessage('Položky se nepodařilo přidat.', 'error');
                $this->redirect("default");
            }
        }
    }

    public function actionDelete($id)
    {

        if ($this->enableDeletes) {
            $submodule = $this->getParameter("submodule", null);
            $postRepository = $this->getDatabaseSelection($submodule);
            $postRepository->where("id = ?", $id)->delete();
            $this->flashMessage("Item has been deleted");
        }

        $this->redirectUrl($_SERVER['HTTP_REFERER']);
        exit;
    }

    public function actionReorderItems()
    {

        $rows = explode(";", $_POST["rows"]);

        foreach ($rows as $key => $row) {
            if (!$row) {
                unset($rows[$key]);
            }
        }

        $max = count($rows) + 1;

        $i = 0;
        foreach ($rows as $row) {
            $i++;
            $priority = ($max - $i) * 10;
            $rowRecord = $this->getDatabaseSelection()->where("id = ?", $row)->fetch();
            $rowRecord->update(["order" => $priority]);
        }

        echo 1;
        die;
    }

    protected function fireEvent($eventType, $resultState, $values)
    {
        return false;
    }

    public function validateForm(Form $form)
    {
        return true;
    }

    public function getThumbnailOrLink($fileId)
    {
        try {
            /** @var FileRepository $fileRepository */
            $fileRepository = $this->context->getService("fileRepository");
            $fileType = $fileRepository->getFileType($fileId);
            if ($fileType === "image") {
                return '<img src="/data/' . $fileRepository->getFilenameResized($fileId, 100) . '" />';
            } else {
                $path = "/data/" . $fileRepository->getFilename($fileId);
                return '<a target="_blank" href="' . $path . '">Open file</a>';
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return "";
    }
}