<?php
require_once '../inc/init.php';
require_once 'Config.php';
require_once 'Database.php';
class ServerMgmtException extends Exception {
    public function __construct($message, $code = 0, Exception $previous = null) {
        $message = json_encode(array(
                "error" => $message 
        ), JSON_UNESCAPED_UNICODE);
        parent::__construct($message, $code, $previous);
    }
}
class ServerMgmt {
    /**
     * Добавление сервера в базу данных.
     * В ответ клиенту отправляет json_data record_id.
     * В случае успешного добавления сервера клиенту возвращается запись о созданном сервере
     *
     * @param json_data $body
     *            - данные о сервере (common_name, visible_label)
     */
    public function addServer($body) {
        $server_type_id = getObjectTypeIdByName("Server");
        if ($server_type_id <= 0) {
            throw new ServerMgmtException("Невозможно получить ID типа для объекта типа 'Server'");
        }
        
        $body = json_decode($body);
        if (empty($body->common_name)) {
            throw new ServerMgmtException("Поле 'common_name' обязательно для создания сервера");
        }
        
        if (empty($body->visible_label)) {
            throw new ServerMgmtException("Поле 'visible_label' обязательно для создания сервера");
        }
        
        try {
            beginTrans();
            try {
                $record_id = commitAddObject(trim($body->common_name), trim($body->visible_label), $server_type_id, "");
            } catch(InvalidRequestArgException $e) {
                throw new ServerMgmtException("Такой объект уже есть");
            }
            
            $proc = empty($body->proc) ? null : $this->prepareProc($body->proc);
            if (isset($proc)) {
                commitUpdateAttrValue($record_id, getAttrId(CPU_TYPE_COUNT), sprintf("%s / %d", $proc ["model"], $proc ["count"]));
                
                commitUpdateAttrValue($record_id, getAttrId(CPU_FREQ), $proc ["freq"]);
            }
            
            $mem = empty($body->mem) ? null : $this->prepareMem($body->mem);
            if (isset($mem)) {
                commitUpdateAttrValue($record_id, getAttrId(MEMORY), $mem);
            }
            
            $ifs = empty($body->if) ? null : $this->prepareIf($body->if);
            if (isset($ifs) && ($count = count($ifs)) > 0) {
                for($i = 0; $i < $count; $i++) {
                    bindIPToObject($ifs [$i]->addr, $record_id, $ifs [$i]->name, "regular");
                }
            }
            
            commitTrans();
            
            $eths = empty($body->eth) ? null : $this->prepareEth($body->eth);
            if (isset($eths) && count($eths) > 0) {
                usePreparedDeleteBlade("Port", array(
                        "object_id" => $record_id 
                ));
                foreach($eths as $eth) {
                    try {
                        commitAddPort($record_id, $eth->name, "1-24", "", $eth->hwaddr);
                    } catch(InvalidRequestArgException $e) {
                    }
                }
            }
            
            $fcs = empty($body->fc) ? null : $this->prepareFc($body->fc);
            if (isset($fcs) && count($fcs) > 0) {
                foreach($fcs as $fc) {
                    try {
                        commitAddPort($record_id, $fc->name, "9-50032", "", $fc->wwn);
                    } catch(InvalidRequestArgException $e) {
                    }
                }
            }
            
            echo json_encode(array("record_id" => $record_id), JSON_UNESCAPED_UNICODE);
        } catch(PDOException $e) {
            rollbackTrans();
            throw new ServerMgmtException("Ошибка при добавлении объекта в базу");
        }
    }
    
    /**
     * Подготовка данных с информацией об используемых оптических портах
     * 
     * @param array $data
     *            массив с информацией об используемых оптических портах
     * @return array массив с информацией об используемых оптических портах
     */
    private function prepareFc($data) {
        $fcs = array();
        foreach($data as $fc) {
            if (empty($fc->name) || empty($fc->wwn) || !$this->is_wwn_valid($fc->wwn)) {
                continue;
            }
            $fc->name = trim($fc->name);
            $fc->wwn = trim($fc->wwn);
            $fcs [] = $fc;
        }
        
        return $fcs;
    }
    
    /**
     * Проверяет корректность WWN-a
     * @param string $wwn wwn для проверки
     * @return true если WWN верен
     */
    private function is_wwn_valid($wwn) {
        return preg_match("/^[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}:[0-9a-f]{2}$/", strtolower($wwn)) == 1;
    }
    
    /**
     * Подготовка данных с информацией о мак-адерсах
     *
     * @param array $data
     *            массив с информацией о мак адресах
     * @return array массив с информацией о мак адресах
     */
    private function prepareEth($data) {
        $eths = array();
        foreach($data as $eth) {
            
            if (empty($eth->name) || empty($eth->hwaddr) || !filter_var($eth->hwaddr, FILTER_VALIDATE_MAC)) {
                continue;
            }
            
            $eth->name = trim($eth->name);
            $eth->hwaddr = trim($eth->hwaddr);
            $eths [] = $eth;
        }
        
        return $eths;
    }
    
    /**
     * Подготовка данных с информацией о процессоре
     *
     * @param Object $data
     *            объект содержащий информацию о процессоре
     * @return array Подготовленный массив с информацией о процессорах
     */
    private function prepareProc($data) {
        $model = empty($data->model) ? "Unknown" : $data->model;
        $freq = $this->is_positive_numeric($data->freq) ? $data->freq : null;
        $count = $this->is_positive_numeric($data->count) ? $data->count : 0;
        
        return array(
                "model" => $model,
                "freq" => $freq,
                "count" => $count 
        );
    }
    
    /**
     * Подготовка данных с информацией об оперативной памяти
     *
     * @param Object $data
     *            объект содержащий информацию об оперативной памяти
     * @return int Объем оперативной памяти в Гб
     */
    private function prepareMem($data) {
        $mem = $this->is_positive_numeric($data->size) ? $data->size : 0;
        
        return round($mem / 1024);
    }
    
    /**
     * Подготовка данных с информацией об используемых сетевых адресах
     *
     * @param array $data
     *            массив содержащий информацию об используемых сетевых адресах
     * @return array подготоволенный массив с информацией об используемых сетевых адресах
     */
    private function prepareIf($data) {
        $ifs = array();
        foreach($data as $if) {
            
            if (empty($if->name) || empty($if->addr) || !filter_var($if->addr, FILTER_VALIDATE_IP)) {
                continue;
            }
            
            $if->name = trim($if->name);
            $if->addr = ip_parse($if->addr);
            $ifs [] = $if;
        }
        
        return $ifs;
    }
    /**
     * Проверяет являеется ли переданная строка положительным числом
     *
     * @param string $val            
     * @return true если переданное значение является положительным числом
     */
    private function is_positive_numeric($val) {
        if (!empty($val) && is_numeric($val) && $val > 0) {
            return true;
        } else {
            return false;
        }
    }
}