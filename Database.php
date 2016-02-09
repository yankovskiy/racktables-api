<?php
function getObjectTypeIdByName($name) {
    $chapter_id = CHAP_OBJTYPE;
    $result = usePreparedSelectBlade
    (
            "select dict_key from Dictionary where chapter_id = ? and dict_value = ?",
            array ($chapter_id, $name)
    );
    $row = $result->fetchColumn();
    
    return $row ? $row : -1;
}

function getAttrId ($name)
{
    $result = usePreparedSelectBlade 
    (
            'SELECT `id` FROM `Attribute` WHERE `name` = ?' , 
            array ($name)
    );
    $row = $result->fetchColumn();
    
    return $row ? $row : -1;
}

function beginTrans() {
    global $dbxlink;
    return $dbxlink->beginTransaction();
}

function commitTrans() {
    global $dbxlink;
    return $dbxlink->commit();
}

function rollbackTrans() {
    global $dbxlink;
    return $dbxlink->rollBack();
}