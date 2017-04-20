<?php

/**
 * ------------------------------------------------------------------------------------
 * 预排序遍历树模型
 * ------------------------------------------------------------------------------------
 *
 * 说明: 需要结合 ORM 使用，
 *      因为这是从本人的一个带 ORM 的项目中摘取出来的。
 *
 * @author lanlin
 */
class Mptt {

    //----------------------------------------------------------------------------------

    public $db;       // database instance
    public $lookup;

    // ------------------------------------------------------------------------------

    // 必须字段数组
    public $properties =
    [
        'table_name'    => 'perm_data',
        'id_column'     => 'id',
        'title_column'  => 'perm_name',
        'left_column'   => 'lft',
        'right_column'  => 'rgt',
        'parent_column' => 'parent'
    ];

    //----------------------------------------------------------------------------------

    /**
     * 初始化数据
     * @param array $properties
     */
    public function __construct($properties=[])
    {
        $this->properties = array_merge(
            $this->properties,
            $properties
        );
    }

    //----------------------------------------------------------------------------------

    /**
     *  新增节点
     *
     *  // 根节点添加
     *  $node = $mptt->add(0, 'Main');
     *
     *  // 在第二个位置插入节点（同级）
     *  $mptt->add($node, 'Child 3', 1);
     *
     *  @param  integer     $parent     父节点ID，0为根节点
     *  @param  array       $fields     字段数组
     *  @param  boolean     $position   插入的位置(从0开始)
     *  @return mixed                   新增ID值，或者false
     */
    public function add($parent, $fields, $position = false)
    {
        // 初始化数据，待用
        $this->_init();
        $parent = (int)$parent;

        if ( $parent == 0 || isset($this->lookup[$parent]) )
        {
            // 获取该父节点下的第一级子节点
            $children = $this->get_children($parent, true);

            // 确定插入位置
            if ($position === false)
            {
                $position = count($children);
            }
            else {
                $position = (int)$position;
                if ($position > count($children) || $position < 0)
                {
                    $position = count($children);
                }
            }

            // 该父节点下无子节点，或要插入0号位置
            if (empty($children) || $position == 0)
            {
                // 获取父节点的左边界（该边界向后需要更新）
                $boundary =
                    isset($this->lookup[$parent]) ?
                        $this->lookup[$parent][$this->properties['left_column']] : 0;
            }
            else {
                // 找出当前要插入位置的前一个节点
                $slice = array_slice($children, $position-1, 1);
                $children = array_shift($slice);

                // 获取该节点的右边界（该边界向后需要更新）
                $boundary = $children[$this->properties['right_column']];
            }

            foreach ($this->lookup as $id => $properties)
            {
                // 左边界大于该节点的项目，每个左边界加2
                if ($properties[$this->properties['left_column']] > $boundary)
                {
                    $this->lookup[$id][$this->properties['left_column']] += 2;
                }

                // 右边界大于该节点的项目，每个右边界加2
                if ($properties[$this->properties['right_column']] > $boundary)
                {
                    $this->lookup[$id][$this->properties['right_column']] += 2;
                }
            }

            // 锁表（写锁）
            $this->db->query("LOCK TABLE {$this->properties['table_name']} WRITE");

            // 更新边界值
            $this->db->set(
                $this->properties['left_column'],
                "{$this->properties['left_column']}+2",
                FALSE
            );
            $this->db->where("{$this->properties['left_column']} >", $boundary);
            $this->db->update_all($this->properties['table_name']);


            $this->db->set(
                $this->properties['right_column'],
                "{$this->properties['right_column']}+2",
                FALSE
            );
            $this->db->where("{$this->properties['right_column']} >", $boundary);
            $this->db->update_all($this->properties['table_name']);

            $fields[$this->properties['left_column']]   = $boundary+1;
            $fields[$this->properties['right_column']]  = $boundary+2;
            $fields[$this->properties['parent_column']] = $parent;

            $insert = array_merge(['_id' => mgid()], $fields);
            $this->db->insert($this->properties['table_name'], $insert);

            // 获取新增节点ID
            $node_id = $insert['_id'];

            // 解锁
            $this->db->query('UNLOCK TABLES');

            // 将节点插入到初始化数组中
            $temp = array(
                $this->properties['id_column']      => $node_id,
                $this->properties['left_column']    => $boundary+1,
                $this->properties['right_column']   => $boundary+2,
                $this->properties['parent_column']  => $parent,
            );
            $temp = array_merge($temp, $fields);
            $this->lookup[$node_id] = $temp;

            // 重排序数组
            $this->_reorder_lookup_array();

            return $node_id;
        }

        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  复制一个节点（包括其子节点）到指定节点下
     *
     *  @param  integer     $source     被复制的节点ID
     *  @param  integer     $target     目标节点ID
     *  @param  boolean     $position   目标位置（0为首位）
     *  @return mixed                   被复制到目标后的新ID或false
     */
    public function copy($source, $target, $position = false)
    {

        // 初始化数据，避免重复操作数据库
        $this->_init();

        if ( isset($this->lookup[$source]) && (isset($this->lookup[$target]) || $target == 0) )
        {

            // 获取被复制节点的子节点（所有子节点）
            $source_children = $this->get_children($source);

            // 暂存被复制节点本身
            $sources = array($this->lookup[$source]);

            // 设置被复制节点的新父节点ID
            $sources[0][$this->properties['parent_column']] = $target;

            foreach ($source_children as $child)
            {
                // 向source数组追加获取的子节点
                $sources[] = $this->lookup[$child[$this->properties['id_column']]];
            }

            // 右值-左值+1=需要被更新边界值的步长（即被复制的节点数 x 2）
            $source_rl_difference =
                $this->lookup[$source][$this->properties['right_column']] -
                $this->lookup[$source][$this->properties['left_column']] + 1;

            // 被复制节点原有的左边界值
            $source_boundary = $this->lookup[$source][$this->properties['left_column']];

            // 获取目标节点的子节点（第一级子节点）
            $target_children = $this->get_children($target, true);

            // 是否默认插入到目标子节点的最后
            if ($position === false)
            {
                $position = count($target_children);
            }
            else {
                $position = (int)$position;
                if ($position > count($target_children) || $position < 0)
                {
                    $position = count($target_children);
                }
            }

            // 目标的子节点为空，或者只是插入到首位
            if (empty($target_children) || $position == 0)
            {
                // 界定需要被更新的边界值为大于目标节点的左边界值
                $target_boundary =
                    isset($this->lookup[$target]) ?
                        $this->lookup[$target][$this->properties['left_column']] : 0;
            }
            else {
                // 找到被插入位置的前一个节点
                $slice = array_slice($target_children, $position - 1, 1);
                $target_children = array_shift($slice);

                // 界定需要被更新的边界值为大于被插入位置前一个节点的右边界值
                $target_boundary = $target_children[$this->properties['right_column']];
            }

            // 先开始更新初始化的数组
            foreach ($this->lookup as $id => $properties)
            {

                // 凡是左值大于界定值的节点，左值一律增加步长值
                if ($properties[$this->properties['left_column']] > $target_boundary)
                {
                    $this->lookup[$id][$this->properties['left_column']] += $source_rl_difference;
                }

                // 凡是右值大于界定值的节点，右值一律增加步长值
                if ($properties[$this->properties['right_column']] > $target_boundary)
                {
                    $this->lookup[$id][$this->properties['right_column']] += $source_rl_difference;
                }
            }

            // 锁表（写锁）
            $this->db->query("LOCK TABLE {$this->properties['table_name']} WRITE");

            // 更新数据库相应边界值
            $this->db->set(
                $this->properties['left_column'],
                "{$this->properties['left_column']}+{$source_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['left_column']} >", $target_boundary);
            $this->db->update_all($this->properties['table_name']);


            $this->db->set(
                $this->properties['right_column'],
                "{$this->properties['right_column']}+{$source_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['right_column']} >", $target_boundary);
            $this->db->update_all($this->properties['table_name']);

            // 最后，被复制出的节点数组也需要更新边界值（或加，或减）
            $shift = $target_boundary - $source_boundary + 1;

            // 开始更新被复制的节点边界值
            foreach ($sources as $id => &$properties)
            {
                $properties[$this->properties['left_column']] += $shift;
                $properties[$this->properties['right_column']] += $shift;

                $insert = array_merge(['_id' => mgid()], $properties);
                $this->db->insert($this->properties['table_name'], $insert);
                $node_id = $insert['_id'];

                // 找到可能存在的子节点并更新其新父ID
                foreach ($sources as $key => $value)
                {
                    if ($value[$this->properties['parent_column']]
                        ==
                        $properties[$this->properties['id_column']])
                    {
                        // 更新其父ID为新插入之后的$node_id
                        $sources[$key][$this->properties['parent_column']] = $node_id;
                    }
                }
                // 更新节点ID
                $properties[$this->properties['id_column']] = $node_id;

                // 更新被复制数组中的当前节点
                $sources[$id] = $properties;

            }

            // 销毁循环最后剩余节点数据
            unset($properties);

            // 解锁
            $this->db->query('UNLOCK TABLES');

            $parents = [];
            foreach ($sources as $id => $properties)
            {

                if (count($parents) > 0)
                {
                    // 根据比较右边界值，不是当前父节点的删除
                    while ($parents[count($parents) - 1]['right'] <
                        $properties[$this->properties['right_column']])
                    {
                        // 删除数组最后一个元素
                        array_pop($parents);
                    }
                }

                // 如果还有剩余，那么$parents的最后一个元素，一定是当前节点的父节点
                if (count($parents) > 0)
                {
                    $properties[$this->properties['parent_column']] = $parents[count($parents) - 1]['id'];
                }

                // 向初始化数组插入被复制的节点
                $this->lookup[$properties[$this->properties['id_column']]] = $properties;

                // 添加当前节点到栈数组
                $parents[] = array(
                    'id'    =>  $properties[$this->properties['id_column']],
                    'right' =>  $properties[$this->properties['right_column']]
                );
            }

            // 重新排序初始化数据
            $this->_reorder_lookup_array();

            // 返回被复制节点插入后的新ID
            return $sources[0][$this->properties['id_column']];
        }

        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  删除一个节点，及其子节点
     *
     *  @param  integer     $node       需要删除的节点ID
     *  @return boolean                 true or false
     */
    public function delete($node)
    {
        // 初始化数据
        $this->_init();

        // 检测该节点是否存在
        if (isset($this->lookup[$node]))
        {

            // 获取其子节点（所有子节点）
            $children = $this->get_children($node);

            // 删除所有子节点
            foreach ($children as $child)
            {
                unset($this->lookup[$child[$this->properties['id_column']]]);
            }

            // 锁表（写锁）
            $this->db->query("LOCK TABLE {$this->properties['table_name']} WRITE");

            // 删除数据库中的相应节点
            $where = array(
                "{$this->properties['left_column']} >="
                =>  $this->lookup[$node][$this->properties['left_column']],

                "{$this->properties['right_column']} <="
                =>  $this->lookup[$node][$this->properties['right_column']]
            );

            $this->db->delete_all($this->properties['table_name'], $where);


            // 计算需要更新边界值的步长值（即节点数 x 2）
            $target_rl_difference =
                $this->lookup[$node][$this->properties['right_column']] -
                $this->lookup[$node][$this->properties['left_column']] + 1;

            // 设置界定值为目标节点的左值，以便更新边界值
            $boundary = $this->lookup[$node][$this->properties['left_column']];

            // 接着删除目标节点
            unset($this->lookup[$node]);

            foreach ($this->lookup as $id => $properties)
            {

                // 左值大于界定值的节点，减去步长值
                if ($this->lookup[$id][$this->properties['left_column']] > $boundary)
                {
                    $this->lookup[$id][$this->properties['left_column']] -= $target_rl_difference;
                }

                // 右值大于界定值的节点，减去步长值
                if ($this->lookup[$id][$this->properties['right_column']] > $boundary)
                {
                    $this->lookup[$id][$this->properties['right_column']] -= $target_rl_difference;
                }
            }

            // 更新数据库左右值
            $this->db->set(
                $this->properties['left_column'],
                "{$this->properties['left_column']}-{$target_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['left_column']} >", $boundary);
            $this->db->update_all($this->properties['table_name']);


            $this->db->set(
                $this->properties['right_column'],
                "{$this->properties['right_column']}-{$target_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['right_column']} >", $boundary);
            $this->db->update_all($this->properties['table_name']);

            // 解锁
            $this->db->query('UNLOCK TABLES');

            return true;
        }

        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  根据父ID返回子节点数组（一维数组）
     *
     *  @param  integer     $parent             父ID
     *  @param  boolean     $children_only      true只返回第一级，false返回所有
     *  @return array                           返回子节点一维数组
     */
    public function get_children($parent = 0, $children_only = false)
    {
        $this->_init();

        // 检测父ID是否存在
        if (isset($this->lookup[$parent]) || $parent === 0) {
            $children = [];

            // 获取初始化数组的键值数组
            $keys = array_keys($this->lookup);

            foreach ($keys as $item)
            {
                if (
                    // 当前节点的左值大于目标父节点左值
                    $this->lookup[$item][$this->properties['left_column']] >
                    ($parent !== 0 ? $this->lookup[$parent][$this->properties['left_column']] : 0) &&

                    // 当前节点的右值小于目标父节点右值
                    $this->lookup[$item][$this->properties['left_column']] <
                    ($parent !== 0 ? $this->lookup[$parent][$this->properties['right_column']] : PHP_INT_MAX) &&

                    // 获取所有子节点时直接保存
                    // 获取第一级子节点时，需要判断子节点父ID等于目标父ID
                    (!$children_only ||
                        ($children_only && $this->lookup[$item][$this->properties['parent_column']] == $parent))
                )
                {
                    // 符合条件的节点保存到$children数组
                    $children[$this->lookup[$item][$this->properties['id_column']]] = $this->lookup[$item];
                }
            }
            return $children;
        }

        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  根据父ID，返回其第一级子节点的数量
     *
     *  @param  integer     $parent             父ID
     *  @return integer                         数字或者false
     */
    public function get_children_count($parent)
    {
        $this->_init();

        if (isset($this->lookup[$parent]))
        {
            $result = 0;
            foreach ($this->lookup as $id => $properties)
            {
                if ($this->lookup[$id][$this->properties['parent_column']] == $parent)
                {
                    $result++;
                }
            }
            return $result;
        }
        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  根据父ID，返回所有子节点的数量
     *
     *  @param  integer     $parent             父ID
     *  @return integer                         数字或false
     */
    public function get_descendants_count($parent)
    {
        $this->_init();
        if (isset($this->lookup[$parent]))
        {
            return ($this->lookup[$parent][$this->properties['right_column']] -
                $this->lookup[$parent][$this->properties['left_column']] - 1) / 2;
        }
        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  获取父节点（直接父节点）
     *
     *  @param  integer     $node               节点ID
     *  @return mixed                           返回父节点，如果已经是第一个节点，则返回零
     */
    public function get_parent($node)
    {
        $this->_init();
        if (isset($this->lookup[$node]))
        {
            // 返回父节点，或者0
            return isset($this->lookup[$this->lookup[$node][$this->properties['parent_column']]]) ?
                $this->lookup[$this->lookup[$node][$this->properties['parent_column']]] : 0;
        }
        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  根据节点ID，获取节点直线层级路径
     *
     *  @param  integer     $node               目标节点
     *  @return array                           一维层级数组
     */
    public function get_path($node)
    {
        $this->_init();
        $parents = [];

        if (isset($this->lookup[$node]))
        {

            // 从第一个节点开始，左值小于目标左值，且右值大于目标右值的。自然成层级被循环出
            foreach ($this->lookup as $id => $properties)
                if (
                    $properties[$this->properties['left_column']] < $this->lookup[$node][$this->properties['left_column']] &&
                    $properties[$this->properties['right_column']] > $this->lookup[$node][$this->properties['right_column']]
                ) {
                    // 保存节点到数组
                    $parents[$properties[$this->properties['id_column']]] = $properties;
                }
        }

        // 最后加上目标节点本身
        $parents[$node] = $this->lookup[$node];

        return $parents;
    }

    //----------------------------------------------------------------------------------

    /**
     *  根据节点ID，返回按层级缩进之后的子节点数组
     *
     *  $selectables = $mptt->get_selectables($node_id);
     *  echo '<select name="myselect">';
     *  foreach ($selectables as $value => $caption)
     *      echo '<option value="' . $value . '">' . $caption . '</option>';
     *  echo '</select>';
     *
     *  @param  integer     $node       节点ID
     *  @param  string      $separator  缩进占位符
     *  @return array                   返回的子节点数组
     */
    public function get_selectables($node = 0, $separator = '&#12288;')
    {
        $this->_init();

        if ( isset($this->lookup[$node]) || $node == 0 )
        {
            // 返回数据，临时父节点数组
            $result = $parents = [];

            // 获取目标节点子节点（所有子节点）
            $children = $this->get_children($node);

            if ($node != 0)
            {
                // 将目标节点插入到子节点数组首位
                array_unshift($children, $this->lookup[$node]);
            }

            foreach ($children as $id => $properties)
            {
                if ($properties[$this->properties['parent_column']] == 0)
                {

                    // 数组相加，key值重复的自动忽略重复元素，只增加不同key的元素
                    if (isset($nodes)) { $result += $nodes; }

                    // 重置临时节点数组和临时父节点数组
                    $nodes = $parents = [];
                }

                // 临时父节点数组是否为空
                if (count($parents) > 0)
                {
                    $keys = array_keys($parents);
                    // 删除$parent中key值（也是保存的右值）小于当前节点右值的
                    while (array_pop($keys) < $properties[$this->properties['right_column']])
                    {
                        array_pop($parents);
                    }
                }

                // 添加节点到临时节点数组
                $nodes[$properties[$this->properties['id_column']]] =
                    (!empty($parents) ? str_repeat($separator, count($parents)) : '') .
                    $properties[$this->properties['title_column']];

                // 添加节点到临时父节点数组（key为节点右值）
                $parents[$properties[$this->properties['right_column']]] = $properties[$this->properties['title_column']];
            }

            // 数组相加，key值重复的自动忽略重复元素，只增加不同key的元素
            if (isset($nodes)) { $result += $nodes; }

            return $result;
        }

        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  根据节点ID，获取节点树
     *
     *  @param  integer     $node               目标节点ID
     *  @return array                           树形数组
     */
    public function get_tree($node = 0)
    {

        // 获取子节点（第一级子节点）
        $result = $this->get_children($node, true);

        foreach ($result as $id => $properties)
        {
            // 逐级递归
            $result[$id]['children'] = $this->get_tree($id);
        }

        return $result;
    }

    //----------------------------------------------------------------------------------

    /**
     *  移动一个节点及其子节点到目标节点之下
     *
     *  @param  integer     $source     被移动的节点ID
     *  @param  integer     $target     目标节点ID
     *  @param  boolean     $position   被插入位置
     *  @return boolean                 true or false
     */
    public function move($source, $target, $position = false)
    {
        // 初始化数据
        $this->_init();

        if ( isset($this->lookup[$source]) && (isset($this->lookup[$target]) || $target == 0) &&
            // 注意：目标节点不能属于被移动节点的下属节点
            !in_array($target, array_keys($this->get_children($source)))
        )
        {
            // 将被移动节点的父ID设置为目标节点ID
            $this->lookup[$source][$this->properties['parent_column']] = $target;

            // 获取被移动节点的子节点（所有子节点）
            $source_children = $this->get_children($source);

            // 需要被移动的节点数组，先把该节点本身放进去
            $sources = array($this->lookup[$source]);

            foreach ($source_children as $child)
            {
                // 再把子节点也放进去
                $sources[] = $this->lookup[$child[$this->properties['id_column']]];

                // 然后把子节点数组从初始化数组中删除
                unset($this->lookup[$child[$this->properties['id_column']]]);
            }

            // 计算出边界值需要更新的步长值（即节点数 x 2）
            $source_rl_difference =
                $this->lookup[$source][$this->properties['right_column']] -
                $this->lookup[$source][$this->properties['left_column']] + 1;

            // 设置界定值，以确定需要更新的边界从何处开始（被移动节点的左值）
            $source_boundary = $this->lookup[$source][$this->properties['left_column']];

            // 锁表（写锁）
            $this->db->query("LOCK TABLE {$this->properties['table_name']} WRITE");

            $this->db->set(
                $this->properties['left_column'],
                "{$this->properties['left_column']}*-1",
                FALSE
            );
            $this->db->set(
                $this->properties['right_column'],
                "{$this->properties['right_column']}*-1",
                FALSE
            );

            // 先将需要被移动的节点的左右值，均设置为负数，以便后续的更新影响到这些值
            $where = array(
                "{$this->properties['left_column']} >="
                =>  $this->lookup[$source][$this->properties['left_column']],

                "{$this->properties['right_column']} <="
                =>  $this->lookup[$source][$this->properties['right_column']]
            );

            $this->db->where($where);
            $this->db->update_all($this->properties['table_name']);

            // 从初始化数组中删除被移动节点
            unset($this->lookup[$source]);

            foreach ($this->lookup as $id=>$properties)
            {
                // 凡是左值大于界定值的节点，其左值减去步长值
                if ($this->lookup[$id][$this->properties['left_column']] > $source_boundary)
                {
                    $this->lookup[$id][$this->properties['left_column']] -= $source_rl_difference;
                }

                // 凡是右值大于界定值的节点，其右值减去步长值
                if ($this->lookup[$id][$this->properties['right_column']] > $source_boundary)
                {
                    $this->lookup[$id][$this->properties['right_column']] -= $source_rl_difference;
                }
            }

            // 用上述同样的条件更新数据库中节点的左右值
            $this->db->set(
                $this->properties['left_column'],
                "{$this->properties['left_column']}-{$source_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['left_column']} >", $source_boundary);
            $this->db->update_all($this->properties['table_name']);


            $this->db->set(
                $this->properties['right_column'],
                "{$this->properties['right_column']}-{$source_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['right_column']} >", $source_boundary);
            $this->db->update_all($this->properties['table_name']);

            // 获取目标节点的子节点（第一级节点）
            $target_children = $this->get_children((int)$target, true);

            // 确定插入位置
            if ($position === false)
            {
                $position = count($target_children);
            }
            else {
                $position = (int)$position;
                if ($position > count($target_children) || $position < 0)
                {
                    $position = count($target_children);
                }
            }

            if (empty($target_children) || $position == 0)
            {
                // 设置目标界定值为目标节点的左值
                $target_boundary = isset($this->lookup[$target]) ?
                    $this->lookup[$target][$this->properties['left_column']] : 0;
            }
            else {
                // 获取目标位置前一个节点
                $slice = array_slice($target_children, $position - 1, 1);
                $target_children = array_shift($slice);

                // 设置目标界定值为目标位置前一个节点的右值
                $target_boundary = $target_children[$this->properties['right_column']];
            }

            foreach ($this->lookup as $id => $properties)
            {
                // 凡是左值大于界定值的节点，左值加步长值
                if ($properties[$this->properties['left_column']] > $target_boundary)
                {
                    $this->lookup[$id][$this->properties['left_column']] += $source_rl_difference;
                }

                // 凡是右值大于界定值的节点，右值加步长值
                if ($properties[$this->properties['right_column']] > $target_boundary)
                {
                    $this->lookup[$id][$this->properties['right_column']] += $source_rl_difference;
                }
            }

            // 依据上述条件更新数据库相应范围的左右值
            $this->db->set(
                $this->properties['left_column'],
                "{$this->properties['left_column']}+{$source_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['left_column']} >", $target_boundary);
            $this->db->update_all($this->properties['table_name']);


            $this->db->set(
                $this->properties['right_column'],
                "{$this->properties['right_column']}+{$source_rl_difference}",
                FALSE
            );
            $this->db->where("{$this->properties['right_column']} >", $target_boundary);
            $this->db->update_all($this->properties['table_name']);

            // 设置被移动节点及子节点本身步长值（或加，或减）
            $shift = $target_boundary - $source_boundary + 1;

            foreach ($sources as $properties)
            {
                $properties[$this->properties['left_column']] += $shift;
                $properties[$this->properties['right_column']] += $shift;

                // 将节点逐个添加到初始化数组
                $this->lookup[$properties[$this->properties['id_column']]] = $properties;
            }

            // 更新被移动节点的所有子节点的左右值
            $this->db->set(
                $this->properties['left_column'],
                "({$this->properties['left_column']}-{$shift})*-1",
                FALSE
            );
            $this->db->set(
                $this->properties['right_column'],
                "({$this->properties['right_column']}-{$shift})*-1",
                FALSE
            );
            $this->db->where("{$this->properties['left_column']} <", 0);
            $this->db->update_all($this->properties['table_name']);

            // 更新被移动节点本身
            $this->db->where($this->properties['id_column'], $source);
            $this->db->update_all($this->properties['table_name'],
                array(
                    $this->properties['parent_column'] => $target
                )
            );

            // 解锁
            $this->db->query('UNLOCK TABLES');

            // 初始化数组重排序
            $this->_reorder_lookup_array();

            return true;
        }

        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  更新指定节点数据
     *
     *  @param  integer    $node        节点ID
     *  @param  array      $fields      字段数组
     *  @return boolean                 true or false
     */
    public function update($node, $fields)
    {
        // 初始化数据
        $this->_init();

        if (isset($this->lookup[$node]))
        {
            // 锁表（写锁）
            $this->db->query("LOCK TABLE {$this->properties['table_name']} WRITE");

            $this->db->where($this->properties['id_column'], $node);
            $this->db->update_all($this->properties['table_name'], $fields);

            // 解锁
            $this->db->query('UNLOCK TABLES');

            // 更新初始化数组
            $this->lookup[$node] = array_merge($this->lookup[$node], $fields);

            return true;
        }

        return false;
    }

    //----------------------------------------------------------------------------------

    /**
     *  根据节点ID，返回列表
     *
     *  echo $mptt->to_list(0, 'ol', 'class="mylist"');
     *
     *  @param  array|int   $node           节点ID
     *  @param  string      $list_type      列表类型 ul 或者 ol
     *  @param  string      $attributes     html属性 "class" or "style"等
     *  @return string
     */
    public function to_list($node, $list_type = 'ul', $attributes = '')
    {
        // 如果节点是ID，先获取节点树
        if (!is_array($node)) $node = $this->get_tree($node);

        if (!empty($node))
        {
            $out = '<' . $list_type . ($attributes != '' ? ' ' . $attributes : '') . '>';

            foreach ($node as $key => $elem)
            {
                // 拼接列表
                $out .= '<li>' . $elem[$this->properties['id_column']] . ':'
                    . $elem[$this->properties['title_column']]
                    . (is_array($elem['children']) ? $this->to_list($elem['children'], $list_type) : '')
                    . '</li>';
            }

            return $out . '</' . $list_type . '>';
        }

        return '';
    }

    //----------------------------------------------------------------------------------

    /**
     *  初始化数据，一次性查出所有数据备用
     *
     *  @return void
     *  @access protected
     */
    protected function _init()
    {
        if (!isset($this->lookup))
        {
            $this->db->order_by($this->properties['left_column'], 'asc');
            $result = $this->db->get($this->properties['table_name']);

            $this->lookup = [];

            foreach ($result as $row)
            {
                // 用节点ID作为数组索引
                $this->lookup[$row[$this->properties['id_column']]] = $row;
            }
        }
    }

    //----------------------------------------------------------------------------------

    /**
     *  重排序初始化数组
     *
     *  @return void
     *  @access protected
     */
    protected function _reorder_lookup_array()
    {
        foreach ($this->lookup as $properties)
        {
            // 用左值字段名创建新数组暂存各节点左值
            ${$this->properties['left_column']}[] = $properties[$this->properties['left_column']];
        }

        // 按升序重排数组
        array_multisort(${$this->properties['left_column']}, SORT_ASC, $this->lookup);

        $tmp = [];
        foreach ($this->lookup as $properties)
        {
            // 逐个将节点寄存到临时数组
            $tmp[$properties[$this->properties['id_column']]] = $properties;
        }

        $this->lookup = $tmp;
    }

    //----------------------------------------------------------------------------------

}