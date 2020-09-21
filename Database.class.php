<?php
/**
 * purpose of file:
 * @author: darwin
 * @date: 3/3/16 10:40 AM
 */
require_once  (ROOT_DIR."/classes/traits/Escape.trait.php");
require_once (ROOT_DIR."/classes/Escape.class.php");
class Database {
    public $conn;

    public function __construct($database="main"){
        if($database == "pixel_board"){
            //echo "Database is pixel board";
            $sql_host = "localhost";
            $sql_user = "startpee_pboard";
            $sql_pass = "3zadbu#$%^";
            $sql_name = "startpee_pixel_board";
            // Connect to SQL Server
            $conn2 = new mysqli($sql_host, $sql_user, $sql_pass, $sql_name);
            $conn2->set_charset("utf8");

            // Check connection
            if ($conn2->connect_errno)
            {
                echo $conn2->connect_error;
                exit($conn2->connect_errno);
            }
            $this->conn = $conn2;
            //echo " -- Connected!";
        }elseif($database == "adzbuzz-services"){
           include_once(ROOT_DIR."/assets/includes/services_db_config.php");
            // Connect to SQL Server
            $conn3 = new mysqli($sql_host_services, $sql_user_services, $sql_pass_services, $sql_name_services);
            $conn3->set_charset("utf8");

            // Check connection
            if ($conn3->connect_errno)
            {
                echo $conn3->connect_error;
                exit($conn3->connect_errno);
            }
            $this->conn = $conn3;
            //echo " -- Connected!";
        }else{ //database is main
            global $conn;
            if(!$conn){
                global $sql_host, $sql_user, $sql_pass, $sql_name;
                // Connect to SQL Server
                $conn = new mysqli($sql_host, $sql_user, $sql_pass, $sql_name);
                $conn->set_charset("utf8");
                //<!-- Check connection
                if ($conn->connect_errno)
                {
                    echo $conn->connect_error;
                    exit($conn->connect_errno);
                }
                //-->
            }
            $this->conn = $conn;
        }

    }

    function db_update($data,$table,$where){
        if(!is_array($data) || empty($table)) return false;
        if(!empty($where)){
            if(is_array($where)){
                $where_clause = "";
                foreach($where as $key=>$value) $where_clause .= (empty($where_clause)?"WHERE ":" AND ")."$key='$value'";
            }else $where_clause = $where;
        }else $where_clause = "";
        $update_clause = "";
        $escape = new \SocialKit\Escape();
        foreach($data as $key=>$value){
            $value = $escape->stringEscape($value);
            $update_clause .= (empty($update_clause)?"":",")."$key='$value'";
        }
        $sql = "UPDATE `$table` SET $update_clause $where_clause";
        $query = $this->conn->query($sql);
        return (false !== $query);
    }

    function db_insert($data,$table){
        if(!is_array($data) || empty($table)) return false;
        $columns = implode(",",array_keys($data));
        $columns = str_replace(",","`,`",$columns);
        $columns = "`$columns`";
        $values="";
        $escape = new \SocialKit\Escape();
        foreach($data as $key=>$value){
            $value = $escape->stringEscape($value);
            $values .= empty($values)?"'$value'":",'$value'";
        }
        $sql = "INSERT INTO `$table` ($columns) VALUES ($values)";
        //echo $sql;
        $query = $this->conn->query($sql);
        return (false !== $query);
    }

    function db_get($table,$where="",$fields="*",$order_by="",$limit="",$group_by=""){
        if(empty($table)) return false;
        if(!empty($where)){
            if(is_array($where)){
                $where_clause = "";
                foreach($where as $key=>$value) $where_clause .= (empty($where_clause)?"WHERE ":" AND ")."$key='$value'";
            }else $where_clause = $where;
        }else $where_clause = "";
        $fields_text = !empty($fields)?$fields:"*";
        $sql = "select $fields_text from $table $where_clause";
        if(!empty($group_by)) $sql .= " $group_by";
        if(!empty($order_by)) $sql .= " $order_by";
        if(!empty($limit)) $sql .= " $limit";
        //echo "<hr>".$sql;
        if(false === $sql){
            die(htmlspecialchars($this->conn->error));
        }
        $query = $this->conn->query($sql);
        if(isset($query->num_rows) && $query->num_rows > 0){
            //$result = $query->fetch_assoc();
            return $query;
        }
        return false;
    }

    /**
     * basic / bare database query to the database
     * @param $sql - this is should a valid query string
     * @return bool|mysqli_result
     */
    function db_query($sql){
        if(empty($sql)) return false;
        //echo "<hr>$sql";
        if(false === ($query = $this->conn->query($sql))) return false;
        return $query;
    }

    function db_get_left_join($table,$table_joins,$where="",$fields="*",$order_by="",$limit="",$group_by=""){
        if(empty($table)) return false;
        if(!empty($table_joins)){
            if(is_array($table_joins)){
                $join_clause = "";
                foreach($table_joins as $join_table=>$joins) $join_clause .= " left join $join_table on $joins";
            }else $join_clause = $table_joins;
        }else $join_clause = "";

        if(!empty($where)){
            if(is_array($where)){
                $where_clause = "";
                foreach($where as $key=>$value) $where_clause .= (empty($where_clause)?"WHERE ":" AND ")."$key='$value'";
            }else $where_clause = $where;
        }else $where_clause = "";
        $fields_text = !empty($fields)?$fields:"*";
        $sql = "select $fields_text from $table $join_clause $where_clause";
        if(!empty($group_by)) $sql .= " $group_by";
        if(!empty($order_by)) $sql .= " $order_by";
        if(!empty($limit)) $sql .= " $limit";
        //echo "<hr>". $sql;
        $query = $this->conn->query($sql);
        if(isset($query->num_rows) && $query->num_rows > 0){
            //$result = $query->fetch_assoc();
            return $query;
        }
        return false;
    }

    function db_delete($table,$where){
        if(empty($table) || empty($where)) return false;
        if(is_array($where)){
            $where_clause = "";
            foreach($where as $key=>$value) $where_clause .= (empty($where_clause)?"WHERE ":" AND ")."$key='$value'";
        }else $where_clause = $where;
        $sql = "DELETE FROM `$table` $where_clause";
        $query = $this->conn->query($sql);
        return (false !== $query);
    }

    function test(){
        //return $this->db_get("users");
        $where = "where position not in (select x from blocks)";
        return $this->db_get("pixel_positions",$where,"position","order by rand()","limit 1");
    }

    function insert_id(){
        return $this->conn->insert_id;
    }

    function affected_rows(){
        return $this->conn->affected_rows;
    }

    function last_error(){
        return $this->conn->error;
    }
}