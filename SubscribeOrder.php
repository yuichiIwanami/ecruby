<?php

/**
 * This is the custom model class for table "dtb_subscribe_order".
 *
 * The followings are the available columns in table 'dtb_subscribe_order':
 */
class SubscribeOrder extends SubscribeOrderBase
{

    const SUBSCRIBE_END = 0; // 終了
    const SUBSCRIBE_CONTINUE = 1; // 継続

    /**
     * このモデル（テーブル）とリレーションしたいモデル（テーブル）を記述
     * join とか使わないで、これを使うと楽。
     * 使い方＝「self::model()->with('myRelationName')->findAll()」
     *   self::HAS_MANY   ... 自分とターゲットの関係は１対
     *   self::HAS_ONE    ... 自分とターゲットの関係は１対１
     *   self::BELONGS_TO ... 自分とターゲットの関係はＮ対１
     *   self::MANY_MANY  ... 自分とターゲットの関係はＮ対Ｎ
     */
    public function relations() {
        return array(
            "details" => array(self::HAS_MANY, 'SubscribeOrderDetail', 'subscribe_id'),
            "order" => array(self::HAS_ONE, 'Order', array('order_id' => 'origin_order_id')),
            "baseinfo" => array(self::BELONGS_TO, 'SubscribeBaseinfo', array('shop_id' => 'shop_id')),
            'customer' => array(self::BELONGS_TO, 'Customer', 'customer_id'),
        );
    }

    /**
     * 定期購入を終了/キャンセルする
     * @return boolean success
     */
    public function finish()
    {
        $this->status = SubscribeOrder::SUBSCRIBE_END;
        $this->next_date = null;
        $this->next_batch_date = null;
        $this->skip_flg = 0;
        return $this->save();
    }


    /**
     * 本日受注レコードを生成すべき定期購入レコードを抽出
     * @param  string today                日付(date('Y-m-d'))
     * @return array subscribeOrders
     */
    public function getSubscribeOrdersForCreateOrders($today) {
        $criteria = new CDbCriteria();
        $criteria->addCondition("{$this->tableAlias}.del_flg = 0");
        $criteria->addCondition("{$this->tableAlias}.create_order_error_flg = 0");
        $criteria->addCondition("{$this->tableAlias}.status = " . SubscribeOrder::SUBSCRIBE_CONTINUE );  // 定期購入継続中
        $criteria->addCondition("{$this->tableAlias}.next_batch_date <= '{$today}' ");  // 今日以前のもの
        $criteria->order = "{$this->tableAlias}.shop_id ASC";
        return self::model()
            ->with('details')
            ->with('order')
            ->with('baseinfo')
            ->with('customer')
            ->findAll($criteria);
    }

    /**
     * 顧客IDで絞り込む
     * @param integer cutomer_id
     */
    public function filterByCustomerId($customerId)
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition('del_flg = 0');
        $criteria->addCondition('customer_id = :customer_id');
        $criteria->params = array(':customer_id' => $customerId);
        $this->getDbCriteria()->mergeWith($criteria);
        return $this;
    }

    /**
     *  定期購入レコードを元に、受注データを生成する
     * @return boolean whether the generating succeeds
     */
    public function generateOrder() {

        $subscribeOrderDetails = $this->getRelated('details');
        $baseinfo = $this->getRelated('baseinfo');
        $order = $this->getRelated('order');
        $customer = $this->getRelated('customer');

        if (!isset($customer) || $customer->del_flg == 1) {
            $this->addError('customer', Yii::t('yii', 'failed to find a valid customer'));
            return false;
        }

        /**
         * 頒布会対応
         * 毎回商品が変わるため価格を再計算して上書きする
         */
        $isBuyingClub = false;
        $buyingClubProducts = array();
        $curr_count = $this->total_count - $this->remaining_count;
        foreach ($subscribeOrderDetails as $detail) {
            if (!$isBuyingClub && $detail->isBuyingClub()) {
                $isBuyingClub = true;
            }
            $tmpProducts = $this->getSortedProductClassList($detail->product_id);
            $tmpProduct = $tmpProducts[$curr_count];
            $buyingClubProducts[] = array("detail" => $detail, "product" => $tmpProduct);
        }
        if ($isBuyingClub) {
            $buyingClubResult = $this->calcPayment($this->shop_id, $this->payment_id, $buyingClubProducts);
        }

        /*
         * create dtb_order
         */
        // TODO dtb_subscribe_orderで完結
        // 本来はdtb_subscribe_orderの情報だけを利用して新規レコードを作成すべき

        $newOrder = new Order();

        $orderId = $newOrder->getNextId("dtb_order_sequence", "id");

        $newOrder->setAttributes($order->getAttributes());
        $newOrder->order_id = $orderId;
        $newOrder->customer_id = $this->customer_id;
        $newOrder->subscribe_id = $this->subscribe_id;
        $newOrder->subtotal = $isBuyingClub ? $buyingClubResult["subtotal"] : $this->subtotal;
        $newOrder->discount = $this->discount;
        $newOrder->deliv_fee = $isBuyingClub ? $buyingClubResult["deliv_fee"] : $this->deliv_fee;
        $newOrder->charge  = $this->charge;
        // 消費税率変更対応（過去の受注でなく、最新の税率を見るように）
        $newOrder->tax = $isBuyingClub ? $buyingClubResult["tax"] : $this->tax;
        $newOrder->total = $isBuyingClub ? $buyingClubResult["total"] : $this->total;
        $newOrder->payment_total = $isBuyingClub ? $buyingClubResult["payment_total"] : $this->payment_total;
        $newOrder->payment_id = $this->payment_id;
        $newOrder->payment_method = $this->payment_method;
        // オーダー作成から作られたオーダーには使用ポイントが使われないように0に変更する
        $newOrder->use_point = 0;
        $newOrder->deliv_time_id = $this->deliv_time_id;
        $newOrder->deliv_time = $this->deliv_time;
        $newOrder->deliv_no = $this->deliv_no;
        $newOrder->note = $this->note;
        $newOrder->deliv_date = $this->deliv_date;
        $newOrder->shop_id = $this->shop_id;
        $newOrder->status = Order::ORDER_NEW;
        $newOrder->commit_date = NULL;

        if ($this->gateway_id) {
            if ($order->memo04 == "AUTH") $newOrder->status = Order::ORDER_PAY_WAIT;
            if ($order->memo04 == "CAPTURE") $newOrder->status = Order::ORDER_PRE_END;
        }

        $newOrder->create_date = date('Y-m-d H:i:s');
        $newOrder->update_date = date('Y-m-d H:i:s');
        $newOrder->memo09 = null;
        $newOrder->memo10 = null;
        $newOrder->credit_rate = 0;
        $newOrder->store_id = 0;
        $newOrder->store_receipt = 0;

        if (!$newOrder->save()) {
            $this->addError('order', $newOrder->errors);
            return false;
        }

        /*
         * create dtb_order_details
         */

        foreach ($subscribeOrderDetails as $detail) {
            // TODO dtb_subscribe_order_detailとdtb_order_detailの差
            $newDetail = new OrderDetail();
            $newDetail->order_id = $orderId;
            $newDetail->product_id = $isBuyingClub ? $buyingClubResult["product_id"] : $detail->product_id;
            $newDetail->product_class_id = $isBuyingClub ? $buyingClubResult["product_class_id"] : $detail->product_class_id;
            $newDetail->classcategory_id1 = $isBuyingClub ? $buyingClubResult["classcategory_id1"] : $detail->classcategory_id1;
            $newDetail->classcategory_id2 = $isBuyingClub ? $buyingClubResult["classcategory_id2"] : $detail->classcategory_id2;
            $newDetail->product_name = $detail->product_name;
            $newDetail->product_code = $isBuyingClub ? $buyingClubResult["product_code"] : $detail->product_code;
            $newDetail->classcategory_name1 = $isBuyingClub ? $buyingClubResult["classcategory_name1"] : $detail->classcategory_name1;
            $newDetail->classcategory_name2 = $isBuyingClub ? $buyingClubResult["classcategory_name2"] : $detail->classcategory_name2;
            $newDetail->price = $isBuyingClub ? $buyingClubResult["price"] : $detail->price;
            $newDetail->discount_price = $detail->discount_price;
            $newDetail->credit_discount_rate = $detail->credit_discount_rate;
            $newDetail->member_discount_rate = $detail->member_discount_rate;
            $newDetail->quantity = $detail->quantity;
            $newDetail->tax = $isBuyingClub ? $buyingClubResult["tax"] : $detail->tax;
            $newDetail->margin = 0;
            $newDetail->point_rate = null;
            $newDetail->shop_id = $this->shop_id;
            $newDetail->product_property1 = $detail->product_property1;
            $newDetail->product_property2 = $detail->product_property2;
            $newDetail->product_property3 = $detail->product_property3;
            $newDetail->product_property4 = $detail->product_property4;
            if (!$newDetail->save()) {
                $this->addError('orderDetail', $newDetail->errors);
                return false;
            }

            // reduce stock
            if (!Product::model()->reduceStock(
                    ($isBuyingClub ? $buyingClubResult["product_id"] : $detail->product_id),
                    ($isBuyingClub ? $buyingClubResult["classcategory_id1"] : $detail->classcategory_id1),
                    ($isBuyingClub ? $buyingClubResult["classcategory_id2"] : $detail->classcategory_id2),
                    $detail->quantity,
                    true  // 第5引数でマイナス在庫を許すようにしている
                )) {
                // TODO get errors from the product model.
                $this->addError('reduceStock', Yii::t('yii', 'failed to reduce stock'));
                return false;
            };
        }

        /*
         * create dtb_shipping
         */
        // TODO dtb_subscribe_orderで完結
        // 本来はdtb_subscribe_orderの情報だけを利用して新規レコードを作成すべき
        $shipping = Shipping::model()->find('order_id=:oid', array(':oid'=>$this->origin_order_id));
        if (empty($shipping)) {
            $this->addError('shipping', Yii::t('yii', 'failed to find a valid shipping'));
            return false;
        }
        $newShipping = new Shipping();

        $newShipping->setAttributes($shipping->getAttributes());


        // 転居等を考慮し、最新の顧客情報から送り先を取得する (定期購入Phase.1ではギフトは考慮せず宛先は常に自分)
        $newShipping->shipping_name01 = $customer->name01;
        $newShipping->shipping_name02 = $customer->name02;
        $newShipping->shipping_kana01 = $customer->kana01;
        $newShipping->shipping_kana02 = $customer->kana02;
        $newShipping->shipping_tel01  = $customer->tel01;
        $newShipping->shipping_tel02  = $customer->tel02;
        $newShipping->shipping_tel03  = $customer->tel03;
        $newShipping->shipping_fax01  = $customer->fax01;
        $newShipping->shipping_fax02  = $customer->fax02;
        $newShipping->shipping_fax03  = $customer->fax03;
        $newShipping->shipping_pref   = $customer->pref;
        $newShipping->shipping_zip01  = $customer->zip01;
        $newShipping->shipping_zip02  = $customer->zip02;
        $newShipping->shipping_addr01 = $customer->addr01;
        $newShipping->shipping_addr02 = $customer->addr02;

        $newShipping->order_id = $orderId;
        $newShipping->shipping_id = 0;
        if (!$newShipping->save()) {
            $this->addError('shipping', $newShipping->errors);
            return false;
        }

        /*
         * create dtb_shipment_item (origin_orderのものをコピー)
         */
        foreach ($subscribeOrderDetails as $detail) {
            $newShipmentItem = new ShipmentItem();
            $newShipmentItem->setAttributes($detail->getAttributes());
            $newShipmentItem->order_id = $orderId;
            $newShipmentItem->shipping_id = 0;
            $newShipmentItem->shop_id = $this->shop_id;
            if (!$newShipmentItem->save()) {
                $this->addError('shipmentItem', $newShipmentItem->errors);
                return false;
            }

            $newOption = new Option();
            $newOption->order_id = $orderId;
            $newOption->shipping_id = 0;
            $newOption->shop_id = $this->shop_id;
            $newOption->product_class_id = $isBuyingClub ? $buyingClubResult["product_class_id"] : $detail->product_class_id;
            $newOption->quantity = $detail->quantity;
            $newOption->option1 = ''; // TODO
            $newOption->option2 = ''; // TODO
            if (!$newOption->save()) {
                $this->addError('option', $newOption->errors);
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $product_id
     * @return array ProductClass numeric sorted by classcategory1 name
     */
    public function getSortedProductClassList($product_id) {
        $product_class_list = ProductClass::model()
            ->with("classcategory1")
            ->findAll("product_id = :product_id", array(':product_id' => $product_id));
        usort($product_class_list, function ($a, $b) {
            $v1 = intval($a->classcategory1()->name);
            $v2 = intval($b->classcategory1()->name);
            if ($v1 == $v2) return 0;
            return $v1 > $v2 ? 1 : -1;
        });
        return $product_class_list;
    }

    /**
     * @param int $shop_id
     * @param int $payment_id
     * @param array $products [ ["detail" => SubjectOrderDetail, "product" => ProductClass ], .. ]
     * @retuan array payment data {tax: FLOAT, subtotal: FLOAT, deliv_fee: INT, is_deliv_free: BOOL, total: FLOAT, payment_total: FLOAT, add_point: FLOAT }
     */
    public function calcPayment($shop_id, $payment_id, $products)
    {
        define ('CLASS_PATH' , DATA_PATH . "/class/");
        define ('CLASS_EX_PATH', DATA_PATH . "/class_extends/");
        define ('CACHE_PATH', DATA_PATH . "/cache/");
        require_once(CLASS_PATH . "SC_Initial.php");

        // EC-CUBEアプリケーション初期化処理
        $objInit = new SC_Initial();
        $objInit->init();

        require_once DATA_PATH . "/include/module.inc";
        require_once DATA_PATH . "/class/SC_Query.php";
        require_once DATA_PATH . "/class/SC_DbConnFactory.php";
        require_once CLASS_EX_PATH . "db_extends/SC_DB_MasterData_Ex.php";
        
        require_once CLASS_PATH . "helper/SC_Helper_Purchase.php";
        require_once CLASS_PATH . "SC_Product.php";
        require_once CLASS_EX_PATH . "helper_extends/SC_Helper_DB_Ex.php";
        require_once CLASS_EX_PATH . "util_extends/SC_Utils_Ex.php";
        
        require_once DATA_PATH . 'class/mdl/MDL_Shop.php';
        MDL_Shop::init();
        
        require_once CLASS_PATH . "SC_CartSession.php";
        require_once CLASS_PATH . "SC_Customer.php";
        require_once CLASS_PATH . "SC_MobileUserAgent.php";
        require_once DATA_PATH . "/module/Net/UserAgent/Mobile/NonMobile.php";

        $objCartSess = new SC_CartSession();
        $objCustomer = new SC_Customer();
        $objPurchase = new SC_Helper_Purchase();
        
        $customer = $this->getRelated('customer');
        $shippingData = array(
            "shipping_name01" => $customer->name01,
            "shipping_name02" => $customer->name02,
            "shipping_kana01" => $customer->kana01,
            "shipping_kana02" => $customer->kana02,
            "shipping_tel01" => $customer->tel01,
            "shipping_tel02" => $customer->tel02,
            "shipping_tel03" => $customer->tel03,
            "shipping_fax01" => $customer->fax01,
            "shipping_fax02" => $customer->fax02,
            "shipping_fax03" => $customer->fax03,
            "shipping_pref" => $customer->pref,
            "shipping_zip01" => $customer->zip01,
            "shipping_zip02" => $customer->zip02,
            "shipping_addr01" => $customer->addr01,
            "shipping_addr02" => $customer->addr02
        );
        $objPurchase->saveShippingTemp($shippingData);
        
        $result = array();
        foreach ($products as $p) {
            $objCartSess->addNewProduct($shop_id, $p["product"]->product_id, $p["product"]->classcategory_id1, $p["product"]->classcategory_id2, $p["detail"]->quantity, true);
            $result["product_id"] = $p["product"]->product_id;
            $result["product_class_id"] = $p["product"]->product_class_id;
            $result["classcategory_id1"] = $p["product"]->classcategory_id1;
            $result["classcategory_id2"] = $p["product"]->classcategory_id2;
            $result["product_code"] = $p["product"]->product_code;
            $result["classcategory_name1"] = $p["product"]->classcategory1()->name;
            $result["classcategory_name2"] = $p["product"]->classcategory2()->name;
            $result["price"] = $p["product"]->price02;
        }
        $result = array_merge($result, $objCartSess->calculate(
            $shop_id,
            $objCustomer,
            0,
            $objPurchase->getShippingPref(),
            0,
            0,
            $payment_id
        ));
        $objCartSess->delAllProducts($shop_id);
        return $result;
    }
}
