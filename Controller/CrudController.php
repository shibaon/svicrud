<?php

namespace Svi\CrudBundle\Controller;

use Doctrine\DBAL\Query\QueryBuilder;
use Svi\HttpBundle\Controller\Controller;
use Svi\HttpBundle\Exception\NotFoundHttpException;
use Svi\HttpBundle\Forms\Field;
use Svi\HttpBundle\Forms\Form;
use Svi\CrudBundle\Bundle;
use Svi\CrudBundle\NestedSortableInterface;
use Svi\CrudBundle\SortableInterface;
use Svi\CrudBundle\RemovableInterface;
use Svi\HttpBundle\Utils\Paginator;
use Svi\HttpBundle\Utils\Sorter;
use Svi\OrmBundle\Entity;
use Svi\OrmBundle\Manager;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class CrudController extends Controller
{
    use \Svi\HttpBundle\BundleTrait;
    use \Svi\FileBundle\BundleTrait;

    public function indexAction()
    {
        if ($this->isSortable()) {
            return $this->getSortableList();
        }

        $routes = $this->getRoutes();

        $templateTable = [
            'columns' => [],
            'rows'    => [],
            'delete'  => isset($routes['delete']) ? $routes['delete'] : null,
            'edit'    => isset($routes['edit']) ? $routes['edit'] : null,
        ];

        $sortableColumns = [];
        foreach ($this->getListColumns() as $key => $c) {
            if (is_string($c) || ((!isset($c['type']) || $c['type'] != 'actions') && (!isset($c['sortable']) || $c['sortable'] !== false))) {
                $sortableColumns[] = $key;
            }
            $templateTable['columns'][$key] = [
                'title' => is_string($c) ? $c : (isset($c['title']) ? $c['title'] : null),
            ];
        }

        $sorter = new Sorter($sortableColumns, $this->getRequest());
        $sorter->processColumns($templateTable['columns']);

        $db = $this->getManager()->getConnection()->createQueryBuilder()
            ->select('e.*')
            ->from($this->getManager()->getTableName(), 'e');

        $filter = $this->createForm(['method' => 'get']);
        $filter->setMethod('get');
        $filter->setId('filter');
        $this->buildFilter($filter);
        if (count($filter->getFields()) == 0) {
            $filter = null;
        }

        if ($filter) {
            /** @var Field $f */
            foreach ($filter->getFields() as $f) {
                $f->setRequired(false);
            }
            $filter->handleRequest($this->getRequest());
            $this->applyFilter($filter->getData(), $db);
        }

        if ($this->getManager()->implementsInterface(RemovableInterface::class)) {
            $db->andWhere('removed <> true');
        }
        $this->modifyQuery($db);
        $paginator = new Paginator($db->select('COUNT(*)')->execute()->fetchColumn(0), $this->getItemsPerPage(), $this->getRequest());

        $db->select('*');
        $db
            ->setFirstResult($paginator->getCurrentPage() * $paginator->getItemsPerPage())
            ->setMaxResults($paginator->getItemsPerPage())
            ->orderBy($this->getManager()->getDbColumnNames()[$sorter->getBy()], $sorter->getOrder());

        $templateTable['rows'] = $this->getTableRows($this->getManager()->fetch($db));

        return $this->render($this->getIndexTemplate(), $this->getTemplateParameters(array(
            'data' => array(
                'filter'     => $filter,
                'add'        => isset($routes['add']) ? $routes['add'] : null,
                'pages'      => $paginator ? $paginator->getView() : false,
                'sorter'     => $sorter,
                'table'      => $templateTable,
                'title'      => $this->getIndexTitle() ? $this->getIndexTitle() : 'crud.title.list' . str_replace('\\', '', $this->getManager()->getEntityClassName()),
                'tableClass' => str_replace('\\', '', $this->getManager()->getEntityClassName()),
            ),
        )));
    }

    public function addAction()
    {
        return $this->getEditView();
    }

    public function editAction($id)
    {
        if (!($entity = $this->getManager()->findOneBy([$this->getEntityIdColumnName() => $id]))) {
            throw new NotFoundHttpException();
        }

        return $this->getEditView($entity);
    }

    function deleteAction($id)
    {
        if (!($entity = $this->getManager()->findOneBy([$this->getEntityIdColumnName() => $id]))) {
            throw new NotFoundHttpException();
        }
        $request = $this->getRequest();

        if ($entity instanceof RemovableInterface) {
            if ($entity->getRemoved()) {
                throw new NotFoundHttpException();
            }
        }

        $formDelete = $this->createForm()
            ->add('delete', 'hidden', [
                'data' => 'delete',
            ])
            ->add('submit', 'submit', [
                'label'    => 'crud.delete',
                'cancel'   => $request->query->has('back') ? $request->query->get('back') : false,
                'template' => 'Views/deleteSubmit',
            ]);

        if ($formDelete->handleRequest($request)->isValid()) {
            if ($formDelete->get('delete')->getData() == 'delete') {
                $this->delete($entity);
                $this->getAlertsService()->addAlert('success', $this->app->getTranslationService()->trans('crud.success.delete'));

                return $this->crudRedirect();
            }
        }

        return $this->render($this->getDeleteTemplate(), $this->getTemplateParameters([
            'className'    => str_replace('\\', '', $this->getManager()->getEntityClassName()),
            'entity'       => $entity,
            'formDelete'   => $formDelete,
            'baseTemplate' => $this->getBaseTemplate(),
        ]));
    }

    protected function getEditView(Entity $entity = null)
    {
        if (!$entity) {
            $entity = $this->getManager()->createEntity();
            $add = true;
        } else {
            $add = false;
        }

        $form = $this->createForm();
        $this->buildForm($form, $entity);

        /** @var Field $value */
        foreach ($form->getFields() as $key => $value) {
            $attr = $value->getAttr();
            if (isset($attr['data-delete']) && $attr['data-delete']) {
                $form->add('deletefile_' . $key, 'hidden');
            }
        }

        $form->add('submit', 'submit', array(
            'label'  => $add ? 'crud.add' : 'crud.save',
            'cancel' => $this->getRequest()->query->has('back') ? $this->getRequest()->query->get('back') : false,
        ));

        if ($form->handleRequest($this->getRequest())->isValid()) {
            $this->checkForm($form, $entity);
            if ($form->isValid()) {
                $this->save($entity, $form, array());

                $this->getAlertsService()->addAlert('success', $add ? $this->app->getTranslationService()->trans('crud.success.add') :
                    $this->app->getTranslationService()->trans('crud.success.edit'));

                return $this->crudRedirect();
            }
        }

        return $this->render($this->getEditTemplate(), $this->getTemplateParameters(array_merge($this->getEditTemplateParameters($add), [
            'form'   => $form,
            'add'    => $add,
            'entity' => $entity,
        ])));
    }

    protected function getSortableList()
    {
        $request = $this->getRequest();
        if ($request->isMethod('post')) {
            return $this->updateWeights();
        }

        if (!$this->getManager()->implementsInterface(SortableInterface::class) &&
            !$this->getManager()->implementsInterface(NestedSortableInterface::class)) {

            throw new \Exception('Sortable CRUD requires what class implements SortableInterface');
        }

        $routes = $this->getRoutes();

        $db = $this->getManager()->getConnection()->createQueryBuilder()
            ->select('e.*')
            ->from($this->getManager()->getTableName(), 'e')
            ->orderBy('weight', 'asc');

        $filter = $this->createForm(['method' => 'get']);
        $filter->setMethod('get');
        $this->buildFilter($filter);
        if (count($filter->getFields()) == 0) {
            $filter = null;
        }

        if ($filter) {
            /** @var Field $f */
            foreach ($filter->getFields() as $f) {
                $f->setRequired(false);
            }
            $filter->handleRequest($this->getRequest());
            $this->applyFilter($filter->getData(), $db);
        }

        if ($this->getManager()->implementsInterface(RemovableInterface::class)) {
            $db->andWhere('removed <> true');
        }
        $this->modifyQuery($db);

        $items = array();
        /** @var SortableInterface|Entity $i */
        foreach ($this->getManager()->fetch($db) as $i) {
            $item = array();
            foreach ($this->getListColumns() as $key => $value) {
                $col = $this->getColumnFieldValue($key, $value, $i);
                $col['colTitle'] = is_string($value) ? $value : @$value['title'];
                $item[$key] = $col;
            }
            if (!isset($item['id'])) {
                $item['id'] = array('type' => 'string', 'value' => $this->getManager()->getFieldValue($i, $this->getEntityIdFieldName()), 'hide' => true, 'notForPrint' => true);
            }
            if ($this->getManager()->implementsInterface(NestedSortableInterface::class)) {
                /** @var NestedSortableInterface|Entity $i */
                $parent = null;
                $item['parent'] = $i->getParentId() ?
                    $this->getManager()->getFieldValue($this->getManager()->findOneBy([$this->getEntityIdColumnName() => $i->getParentId()]), $this->getEntityIdFieldName())
                    : false;
                $item['children'] = array();
            }
            /** @var Entity $i */
            $items[$this->getManager()->getFieldValue($i, $this->getEntityIdFieldName())] = $item;
        }
        if ($this->getManager()->implementsInterface(NestedSortableInterface::class)) {
            foreach ($items as $key => &$value) {
                if ($value['parent']) {
                    $items[$value['parent']]['children'][$key] = &$value;
                }
            }
            unset($value);

            foreach ($items as $key => &$value) {
                if ($value['parent']) {
                    unset($items[$key]);
                }
            }
        }

        return $this->render($this->getSortableTemplate(), $this->getTemplateParameters(array(
            'items'  => $items,
            'routes' => [
                'add'    => isset($routes['add']) ? $routes['add'] : null,
                'delete' => isset($routes['delete']) ? $routes['delete'] : null,
                'edit'   => isset($routes['edit']) ? $routes['edit'] : null,
            ],
            'nested' => $this->getManager()->implementsInterface(NestedSortableInterface::class),
            'filter' => $filter,
        )));
    }

    protected function updateWeights()
    {
        $data = $this->getRequest()->request->all();
        if (!isset($data['weights'])) {
            return new JsonResponse([
                'error' => true,
                'errorMessage' => 'Weights parameters is not specified',
            ]);
        }
        foreach ($data['weights'] as $weight) {
            /** @var SortableInterface $i */
            if ($i = $this->getManager()->findOneBy([$this->getEntityIdColumnName() => $weight['id']])) {
                $i->setWeight($weight['weight']);
                if ($this->getManager()->implementsInterface(NestedSortableInterface::class)) {
                    /** @var NestedSortableInterface|Entity $i */
                    if ($weight['parent'] && $parent = $this->getManager()->findOneBy([$this->getEntityIdColumnName() => $weight['parent']])) {
                        $i->setParentId($this->getManager()->getFieldValue($parent, $this->getEntityIdFieldName()));
                    } else {
                        $i->setParentId(NULL);
                    }
                }
                $this->getManager()->save($i);
            }
        }

        return new JsonResponse([
            'error' => false,
            'weights' => $data['weights'],
        ]);
    }

    abstract protected function getBaseTemplate();

    /**
     * @return Manager
     */
    abstract protected function getManager();

    abstract protected function getListColumns();

    abstract protected function getRoutes();

    protected function getPaginatorMaxPages()
    {
        return 15;
    }

    protected function getItemsPerPage()
    {
        return 15;
    }

    protected function isSortable()
    {
        return false;
    }

    protected function buildForm(Form $form, Entity $entity)
    {
        throw new \Exception('function buildForm not yet implemented in child class');
    }

    protected function buildFilter(Form $builder)
    {
    }

    protected function applyFilter(array $data, QueryBuilder $builder)
    {
        throw new \Exception('function applyFilter not yet implemented in child class');
    }

    protected function checkForm(Form $form, $entity, array $exclude = [])
    {
        $data = $form->getData();

        foreach ($data as $key => $value) {
            if (!in_array($key, $exclude) && strpos($key, 'deletefile_') === false) {
                $this->checkField($form, $entity, $key);
            }
        }
    }

    protected function checkField(Form $form, $entity, $key)
    {
        // There is no default checks of CRUD form fields
    }

    protected function save(Entity $entity, Form $form, array $exclude = [])
    {
        $data = $form->getData();

        foreach ($data as $key => $value) {
            if (!in_array($key, $exclude) && strpos($key, 'deletefile_') === false) {
                $this->saveField($entity, $form, $key);
            }
        }
        $this->getManager()->save($entity);
    }

    protected function saveField(Entity $entity, Form $form, $key)
    {
        $data = $form->getData();
        $value = $data[$key];
        $attr = $form->get($key)->getAttr();

        if (array_key_exists('data-file', $attr)) {
            if ($value) {
                $uri = isset($attr['data-uri']) ? $attr['data-uri'] : 'heap';
                $md5 = md5($value->getFilename());
                $uri .= '/' . substr($md5, 0, 2) . '/' . substr($md5, 2, 2);
            } else {
                $uri = null;
            }

            $this->getManager()->setFieldValue($entity, $key,
                $this->getFileService()->getNewFileUriFromField($this->getManager()->getFieldValue($entity, $key), $value, $uri,
                    !!isset($data['deletefile_' . $key]))
            );
        } else {
            $this->getManager()->setFieldValue($entity, $key, $value);
        }
    }

    protected function getFileFieldAttributes($fileUri, $dir = null, $canDelete = true, $isImage = true)
    {
        $attr = array();
        $attr['data-file'] = $fileUri ? '/files/' . $fileUri : null;
        if ($canDelete) {
            $attr['data-delete'] = true;
        }
        if ($fileUri && $isImage) {
            $attr['data-image'] = $this->getImageService()->getImagePath($fileUri, 120, 80);
        }
        if ($dir) {
            $attr['data-uri'] = $dir;
        }

        return $attr;
    }

    protected function delete(Entity $entity)
    {
        if ($entity instanceof RemovableInterface) {
            $entity->remove();
            $this->getManager()->save($entity);
        } else {
            $this->getManager()->delete($entity);
        }
    }

    protected function modifyQuery(QueryBuilder $builder)
    {
    }

    protected function getTableRows(array $items)
    {
        $rows = array();

        foreach ($items as $i) {
            $row = array();
            foreach ($this->getListColumns() as $key => $c) {
                $row[$key] = $this->getColumnFieldValue($key, $c, $i);
            }
            if (!isset($row[$this->getEntityIdFieldName()])) {
                $row[$this->getEntityIdFieldName()] =
                    array('type' => 'string', 'value' => $this->getManager()->getFieldValue($i, $this->getEntityIdFieldName()), 'hide' => true);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    protected function getColumnFieldValue($key, $column, Entity $item)
    {
        $value = is_string($column) ? NULL : (isset($column['value']) ? $column['value'] : null);
        if (!is_string($column) && isset($column['type']) && $column['type'] == 'actions') {
            $value = $column;
        } else if ($value === NULL) {
            $value = $this->getManager()->getFieldValue($item, $key);
        } else if (is_callable($value)) {
            $value = $value($item);
        }
        if (is_object($value)) {
            $value = $value . '';
        }
        if (!is_array($value)) {
            if (is_bool($value)) {
                $value = array('type' => 'boolean', 'value' => $value);
            } else {
                $value = array('type' => 'string', 'value' => $value);
            }
        } else if (isset($value['type']) && $value['type'] == 'actions') {
            $value['entity'] = $item;
        }

        return $value;
    }

    protected function getTemplateParameters(array $parameters = [])
    {
        return $parameters + [
                'baseTemplate' => $this->getBaseTemplate(),
                'className'    => str_replace('\\', '', $this->getManager()->getEntityClassName()),
                'templates'    => [
                    'delete'        => $this->getDeleteTemplate(),
                    'edit'          => $this->getEditTemplate(),
                    'fields'        => $this->getFieldsTemplate(),
                    'filter'        => $this->getFilterFieldsTemplate(),
                    'index'         => $this->getIndexTemplate(),
                    'paginator'     => $this->getPaginatorTemplate(),
                    'sortable'      => $this->getSortableTemplate(),
                    'sortableItems' => $this->getSortableItemsTemplate(),
                    'table'         => $this->getTableTemplate(),
                    'field'         => $this->getFieldTemplate(),
                ],
            ];
    }

    protected function getDeleteTemplate()
    {
        $this->addTwigPath();

        return 'Views/delete.twig';
    }

    protected function getEditTemplate()
    {
        $this->addTwigPath();

        return 'Views/edit.twig';
    }

    protected function getFieldsTemplate()
    {
        $this->addTwigPath();

        return 'Views/fields.twig';
    }

    protected function getFilterFieldsTemplate()
    {
        $this->addTwigPath();

        return 'Views/filter_fields.twig';
    }

    protected function getIndexTemplate()
    {
        $this->addTwigPath();

        return 'Views/index.twig';
    }

    protected function getPaginatorTemplate()
    {
        $this->addTwigPath();

        return 'Views/paginator.twig';
    }

    protected function getSortableTemplate()
    {
        $this->addTwigPath();

        return 'Views/sortable.twig';
    }

    protected function getSortableItemsTemplate()
    {
        $this->addTwigPath();

        return 'Views/subitems.twig';
    }

    protected function getTableTemplate()
    {
        $this->addTwigPath();

        return 'Views/table.twig';
    }

    protected function getFieldTemplate()
    {
        $this->addTwigPath();

        return 'Views/table_field.twig';
    }

    protected function getEntityIdFieldName()
    {
        $this->addTwigPath();

        return $this->getManager()->getIdFieldName();
    }

    protected function getEntityIdColumnName()
    {
        $this->addTwigPath();

        return $this->getManager()->getIdColumnName();
    }

    protected function crudRedirect($url = NULL)
    {
        $this->addTwigPath();

        return $this->redirectToUrl($this->getBackLink($url));
    }

    protected function getBackLink($url = NULL)
    {
        $request = $this->getRequest();
        if (!$url) {
            if ($request->query->has('back')) {
                $url = $request->query->get('back');
            } else if ($request->headers->get('referer')) {
                $url = $request->headers->get('referer');
            } else {
                $url = $request->getRequestUri();
            }
        }

        return $url;
    }

    protected function getIndexTitle()
    {
        return null;
    }

    protected function getEditTemplateParameters($add = false)
    {
        return [];
    }

    private function addTwigPath()
    {
        $this->getTemplateService()->addLoadPath($this->app[Bundle::class]->getDir());
    }

}
