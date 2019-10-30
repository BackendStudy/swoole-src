<?php
foreach ($data['product'] as $_product) {
    // 有则更新
    $productId = $_product['product_id'];
    $sql = "SELECT item_id from ".DB_PREFIX."product_group_item WHERE product_group_id='".$product_group_id."' AND product_id='".$productId."'";
    $query = $this->db->query($sql);

    if ($query->num_rows) {

     $sql = "UPDATE ".DB_PREFIX."product_group_item set sort_order='".$_product['sort_order']."' WHERE product_group_id='".$product_group_id."' AND product_id='".$productId."'";
     $this->db->query($sql);
    } else {
     // 新增加记录
     $sql = "INSERT INTO ".DB_PREFIX."product_group_item SET 
     product_group_id='".$product_group_id."', 
     product_id='".(int)$_product['product_id']."', 
     spu='".$this->db->escape($_product['spu'])."', 
     sort_order='".$this->db->escape($_product['sort_order'])."'
     ";
     // echo $sql;die;
     $this->db->query($sql);
    }
   }