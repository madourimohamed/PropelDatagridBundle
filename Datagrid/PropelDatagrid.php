<?php

namespace Spyrit\PropelDatagridBundle\Datagrid;

use Spyrit\PropelDatagridBundle\Datagrid\PropelDatagridInterface;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Datagrid management class that support and handle pagination, sort, filter
 * and now, export actions.
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
abstract class PropelDatagrid implements PropelDatagridInterface
{
    /**
     * The container witch is usefull to get Request parameters and differents 
     * options and parameters.
     * @var \Symfony\Component\DependencyInjection\Container
     */
    protected $container;
    
    /**
     * The query that filter the results
     * @var \PropelQuery 
     */
    protected $query;
    
    /**
     * @var FilterObject
     */
    protected $filter;
    
    /**
     * Results of the query (in fact this is a PropelPager object which contains
     * the result set and some methods to display pager and extra things)
     * @var \PropelPager
     */
    protected $results;
    
    /**
     * Number of result(s) to display per page 
     * @var integer 
     */
    protected $maxPerPage;
    
    /**
     * Options that you can use in your Datagrid methods if you need
     * @var integer 
     */
    protected $options;
    
    public function __construct($container, $options = array())
    {
        $this->container = $container;
        $this->options = $options;
        $this->query = $this->configureQuery();
        $this->buildForm();
    }
    
    /**
     * @param type $container
     * @return \self
     */
    public static function create($container)
    {
        $class = get_called_class();
        return new $class($container);
    }
    
    public function execute()
    {
        $this->preExecute();
        
        if($this->getRequest()->get($this->getActionParameterName()) == $this->getResetActionParameterName())
        {
            $this->reset();
        }
        $this->filter();
        $this->manageColumns();
        $this->sort();
        $this->results = $this->getQuery()->paginate($this->getCurrentPage(), $this->getMaxPerPage());
        
        $this->postExecute();
        
        return $this;
    }
    
    public function preExecute()
    {
        return;
    }
    
    public function postExecute()
    {
        return;
    }
    
    public function reset()
    {
        if($this->getRequest()->get($this->getDatagridParameterName()) == $this->getName())
        {
            return $this
                ->resetFilters()
                ->resetSort();
        }
        return $this;
    }
    
    /*********************************/
    /* Filter features here **********/
    /*********************************/
    
    private function filter()
    {
        if(in_array($this->getRequest()->getMethod(), array_map('strtoupper', $this->getAllowedFilterMethods())) && $this->getRequest()->get($this->filter->getForm()->getName()))
        {
            $data = $this->getRequest()->get($this->filter->getForm()->getName());
        }
        else
        {
            $data = $this->getRequest()->getSession()->get($this->getSessionName().'.filter', $this->getDefaultFilters());
        }
        
        $this->filter->submit($data);
        $form = $this->filter->getForm();
        $formData = $form->getData();
        
        if($form->isValid())
        {
            if(in_array($this->getRequest()->getMethod(), array_map('strtoupper', $this->getAllowedFilterMethods())))
            {
                $this->getRequest()->getSession()->set($this->getSessionName().'.filter', $data);
            }
            $this->applyFilter($formData);
        }
        
        return $this;
    }
    
    private function applyFilter($data)
    {
        foreach($data as $key => $value)
        {
            $empty = true;
            
            if(($value instanceof \PropelCollection || is_array($value)))
            {
                if(count($value) > 0)
                {
                    $empty = false;
                }
            }
            elseif(!empty($value) || $value === 0)
            {
                $empty = false;
            }
 
            if(!$empty)
            {
                $method = 'filterBy'.$this->container->get('spyrit.util.inflector')->camelize($key);

                if($this->filter->getType($key) === 'text')
                {
                    $this->getQuery()->$method('%'.$value.'%', Criteria::LIKE);
                }
                else
                {
                    
                    $this->getQuery()->$method($value);
                }
            }
        }
    }
    
    protected function buildForm()
    {
        $filters = $this->configureFilter();
        
        if(!empty($filters))
        {
            $this->filter = new FilterObject($this->getFormFactory(), $this->getName());
            
            foreach($filters as $name => $filter)
            {
                $this->filter->add($name, $filter['type'], isset($filter['options'])? $filter['options'] : array(), isset($filter['value'])? $filter['value'] : null);
            }
            
            $this->configureFilterBuilder($this->filter->getBuilder());
        }
    }
    
    public function setFilterValue($name, $value)
    {
        $filters = $this->getRequest()->getSession()->get($this->getSessionName().'.filter', array());
        $filters[$name] = $value;
        $this->getRequest()->getSession()->set($this->getSessionName().'.filter', $filters);
    }
    
    public function configureFilter()
    {
        return array();
    }
    
    protected function getDefaultFilters()
    {
        return array();
    }
    
    public function resetFilters()
    {
        $this->getRequest()->getSession()->remove($this->getSessionName().'.filter');
        return $this;
    }
    
    /**
     * Shortcut 
     */
    public function getFilterFormView()
    {
        return $this->filter->getForm()->createView();
    }
    
    public function configureFilterForm()
    {
        return array();
    }
    
    public function configureFilterBuilder($builder)
    {
        /**
         * Do what you want with the builder. For example, add Event Listener PRE/POST_SET_DATA, etc.
         */
        return;
    }
    
    public function getAllowedFilterMethods()
    {
        return array('post');
    }
    
    /*********************************/
    /* Sort features here ************/
    /*********************************/
    
    private function sort()
    {
        $this->removeSort();
        $namespace = $this->getSessionName().'.'.$this->getSortActionParameterName();
        
        $sort = $this->getSession()->get($namespace)? $this->getSession()->get($namespace) : $this->getDefaultSort();
        
        if(
            $this->getRequest()->get($this->getActionParameterName()) == $this->getSortActionParameterName() &&
            $this->getRequest()->get($this->getDatagridParameterName()) == $this->getName()
        )
        {
            $sort[$this->getRequest()->get($this->getSortColumnParameterName())] = $this->getRequest()->get($this->getSortOrderParameterName());
            $this->getSession()->set($namespace, $sort);
        }
        foreach($sort as $column => $order)
        {
            $method = 'orderBy'.ucfirst($column);
            try
            {
                $this->getQuery()->$method($order);
            }
            catch(\Exception $e)
            {
                throw new \Exception('There is no method "'.$method.'" to sort the datagrid on column "'.$sort['column'].'". Just create it in the "'.get_class($this->query).'" object.');
            }
        }
    }
    
    public function removeSort()
    {
        $namespace = $this->getSessionName().'.'.$this->getSortActionParameterName();
        if(
            $this->getRequest()->get($this->getActionParameterName()) == $this->getRemoveSortActionParameterName() &&
            $this->getRequest()->get($this->getDatagridParameterName()) == $this->getName()
        )
        {
            $sort = $this->getSession()->get($namespace)? $this->getSession()->get($namespace) : $this->getDefaultSort();
            unset($sort[$this->getRequest()->get($this->getRemoveSortColumnParameterName())]);
            $this->getSession()->set($namespace, $sort);
        }
    }
    
    public function getDefaultSort()
    {
        return array(
            $this->getDefaultSortColumn() => $this->getDefaultSortOrder(),
        );
    }
    
    public function isSortedColumn($column)
    {
        $sort = $this->getSession()->get($this->getSessionName().'.'.$this->getSortActionParameterName(), $this->getDefaultSort());
        return isset($sort[$column]);
    }
    
    public function getSortedColumnOrder($column)
    {
        $sort = $this->getSession()->get($this->getSessionName().'.'.$this->getSortActionParameterName(), $this->getDefaultSort());
        return $sort[$column];
    }
    
    public function getSortedColumnPriority($column)
    {
        $sort = $this->getSession()->get($this->getSessionName().'.'.$this->getSortActionParameterName(), $this->getDefaultSort());
        return array_search($column, array_keys($sort));
    }
    
    public function getSortCount()
    {
        $sort = $this->getSession()->get($this->getSessionName().'.'.$this->getSortActionParameterName(), $this->getDefaultSort());
        return count($sort);
    }
    
    public function resetSort()
    {
        $this->getRequest()->getSession()->remove($this->getSessionName().'.'.$this->getSortActionParameterName());
        return $this;
    }
    
    public function getDefaultSortOrder()
    {
        return strtolower(Criteria::ASC);
    }
    
    /*********************************/
    /* Export features here **********/
    /*********************************/
    
    /**
     * @param type $name
     * @param type $params
     * @return self
     */
    public function export($name, $params = array())
    {
        $class = $this->getExport($name);
        $this->filter();
        $this->sort();
        
        $export = new $class($this->getQuery(), $params);
        return $export->execute();
    }
    
    protected function getExport($name)
    {
        $exports = $this->getExports();
        if(!isset($exports[$name]))
        {
            throw new \Exception('The "'.$name.'" export doesn\'t exist in this datagrid.');
        }
        return $exports[$name];
    }
    
    protected function getExports()
    {
        return array();
    }
    
    public function getMaxPerPage()
    {
        if($this->maxPerPage)
        {
            return $this->maxPerPage;
        }
        return 30;
    }
    
    public function getSessionName()
    {
        return 'datagrid.'.$this->getName();
    }
    
    public function setMaxPerPage($v)
    {
        $this->maxPerPage = $v;
    }
    
    public function getCurrentPage($default = 1)
    {
        $name = $this->getSessionName().'.'.$this->getPageParameterName();
        if($this->getRequest()->get($this->getDatagridParameterName()) == $this->getName())
        {
            $page = $this->getRequest()->get($this->getPageParameterName());
        }
        if(!isset($page))
        {
            $page = $this->getRequest()->getSession()->get($name, $default);
        }
        $this->getRequest()->getSession()->set($name, $page);
        
        return $page;
    }
    
    /*********************************/
    /* Dynamic columns ***************/
    /*********************************/
    
    private function manageColumns()
    {
        if(count($this->getDefaultColumns()) > 0)
        {
            $this->removeColumn();
            $this->addColumn();
        }
    }
    
    private function removeColumn()
    {
        if(
            $this->getRequest()->get($this->getActionParameterName()) == $this->getRemoveColumnActionParameterName() &&
            $this->getRequest()->get($this->getDatagridParameterName()) == $this->getName()
        )
        {
            $namespace = $this->getSessionName().'.columns';
            $columnToRemove = $this->getRequest()->get($this->getRemoveColumnParameterName());
            
            $columns = $this->getColumns();
                    
            if(array_key_exists($columnToRemove, $columns))
            {
                unset($columns[$columnToRemove]);
                $this->getRequest()->getSession()->set($namespace, $columns);
                
                /**
                 * @todo Remove sort on the removed column
                 * The problem is the column name is not the same as the sort name...
                 */
                /*$sortNamespace = $this->getSessionName().'.'.$this->getSortActionParameterName();
                $sort = $this->getSession()->get($sortNamespace)? $this->getSession()->get($sortNamespace) : $this->getDefaultSort();
                unset($sort[$columnToRemove]);
                $this->getSession()->set($namespace, $sort);*/
            }
        }
    }
    
    private function addColumn()
    {
        if(
            $this->getRequest()->get($this->getActionParameterName()) == $this->getNewColumnActionParameterName() &&
            $this->getRequest()->get($this->getDatagridParameterName()) == $this->getName()
        )
        {
            $namespace = $this->getSessionName().'.columns';
            $newColumn = $this->getRequest()->get($this->getNewColumnParameterName());
            $precedingColumn = $this->getRequest()->get($this->getPrecedingNewColumnParameterName());
            
            if(array_key_exists($newColumn, $this->getAvailableAppendableColumns()))
            {
                $columns = $this->getColumns();
                $newColumnsArray = array();
                foreach($columns as $column => $label)
                {
                    $newColumnsArray[$column] = $label;
                    if($column == $precedingColumn)
                    {
                        $cols = array_merge($this->getAppendableColumns(), $this->getDefaultColumns());
                        $newColumnsArray[$newColumn] = $cols[$newColumn];
                    }
                }
                
                $this->getRequest()->getSession()->set($namespace, $newColumnsArray);
            }
        }
    }
    
    public function getDefaultColumns()
    {
        return array();
    }
    
    public function getNonRemovableColumns()
    {
        return array();
    }
    
    public function getAppendableColumns()
    {
        return array();
    }
    
    public function getAvailableAppendableColumns()
    {
        $namespace = $this->getSessionName().'.columns';
        $columns = $this->getSession()->get($namespace, $this->getDefaultColumns());
        
        return array_merge(array_diff_key($this->getAppendableColumns(), $columns), array_diff_key($this->getDefaultColumns(), $columns));
    }
    
    public function getColumns()
    {
        $namespace = $this->getSessionName().'.columns';
        return $this->getSession()->get($namespace, $this->getDefaultColumns());
    }
    
    /*********************************/
    /* Routing helper methods here ***/
    /*********************************/
    
    public function getActionParameterName()
    {
        return 'action';
    }
    
    public function getSortActionParameterName()
    {
        return 'sort';
    }
    
    public function getRemoveSortActionParameterName()
    {
        return 'remove-sort';
    }
    
    public function getPageActionParameterName()
    {
        return 'page';
    }
    
    public function getDatagridParameterName()
    {
        return 'datagrid';
    }
    
    public function getPageParameterName()
    {
        return 'param1';
    }
    
    public function getResetActionParameterName()
    {
        return 'reset';
    }
    
    public function getSortColumnParameterName()
    {
        return 'param1';
    }
    
    public function getSortOrderParameterName()
    {
        return 'param2';
    }
    
    public function getRemoveSortColumnParameterName()
    {
        return 'param1';
    }
    
    public function getNewColumnActionParameterName()
    {
        return 'add-column';
    }
    
    public function getNewColumnParameterName()
    {
        return 'param1';
    }
    
    public function getPrecedingNewColumnParameterName()
    {
        return 'param2';
    }
    
    public function getRemoveColumnActionParameterName()
    {
        return 'remove-column';
    }
    
    public function getRemoveColumnParameterName()
    {
        return 'param1';
    }
    
    /*********************************/
    /* Global service shortcuts ******/
    /*********************************/
    
    /**
     * Shortcut to return the request service.
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function getRequest()
    {
        return $this->container->get('request');
    }
    
    /**
     * Shortcut to return the request service.
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function getSession()
    {
        return $this->container->get('session');
    }
    
    /**
     * return the Form Factory Service
     * @return \Symfony\Component\Form\FormFactory
     */
    protected function getFormFactory()
    {
        return $this->container->get('form.factory');
    }
    
    public function getQuery()
    {
        return $this->query;
    }
    
    public function setQuery($query)
    {
        $this->query = $query;
        return $this;
    }
    
    public function getResults()
    {
        return $this->results;
    }
    
    public function getPager()
    {
        return $this->getResults();
    }
    
    /**
     * Generate pagination route
     * @param type $route
     * @param type $extraParams
     * @return string
     */
    public function getPaginationPath($route, $page, $extraParams = array())
    {
        $params = array(
            $this->getActionParameterName() => $this->getPageActionParameterName(),
            $this->getDatagridParameterName() => $this->getName(),
            $this->getPageParameterName() => $page,
        );
        return $this->container->get('router')->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate reset route for the button view
     * @param type $route
     * @param type $extraParams
     * @return string
     */
    public function getResetPath($route, $extraParams = array())
    {
        $params = array(
            $this->getActionParameterName() => $this->getResetActionParameterName(),
            $this->getDatagridParameterName() => $this->getName(),
        );
        return $this->container->get('router')->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate sorting route for a given column to be displayed in view
     * @todo Remove the order parameter and ask to the datagrid to guess it ?
     * @param type $route
     * @param type $column
     * @param type $order
     * @param type $extraParams
     * @return string
     */
    public function getSortPath($route, $column, $order, $extraParams = array())
    {
        $params = array(
            $this->getActionParameterName() => $this->getSortActionParameterName(),
            $this->getDatagridParameterName() => $this->getName(),
            $this->getSortColumnParameterName() => $column,
            $this->getSortOrderParameterName() => $order,
        );
        return $this->container->get('router')->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate remove sort route for a given column to be displayed in view
     * @todo Remove the order parameter and ask to the datagrid to guess it ?
     * @param type $route
     * @param type $column
     * @param type $extraParams
     * @return string
     */
    public function getRemoveSortPath($route, $column, $extraParams = array())
    {
        $params = array(
            $this->getActionParameterName() => $this->getRemoveSortActionParameterName(),
            $this->getDatagridParameterName() => $this->getName(),
            $this->getRemoveSortColumnParameterName() => $column,
        );
        return $this->container->get('router')->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate new column route for a given column to be displayed in view
     * @todo Remove the order parameter and ask to the datagrid to guess it ?
     * @param type $route
     * @param type $column
     * @param type $precedingColumn
     * @param type $extraParams
     * @return type
     */
    public function getNewColumnPath($route, $newColumn, $precedingColumn, $extraParams = array())
    {
        $params = array(
            $this->getActionParameterName() => $this->getNewColumnActionParameterName(),
            $this->getDatagridParameterName() => $this->getName(),
            $this->getNewColumnParameterName() => $newColumn,
            $this->getPrecedingNewColumnParameterName() => $precedingColumn,
        );
        return $this->container->get('router')->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate remove column route for a given column to be displayed in view
     * @todo Remove the order parameter and ask to the datagrid to guess it ?
     * @param type $route
     * @param type $column
     * @param type $precedingColumn
     * @param type $extraParams
     * @return type
     */
    public function getRemoveColumnPath($route, $column, $extraParams = array())
    {
        $params = array(
            $this->getActionParameterName() => $this->getRemoveColumnActionParameterName(),
            $this->getDatagridParameterName() => $this->getName(),
            $this->getRemoveColumnParameterName() => $column,
        );
        return $this->container->get('router')->generate($route, array_merge($params, $extraParams));
    }
}
