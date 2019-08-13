<?php
/**
 * @author ADVENTOS GmbH, Karsten Hoffmann
 * Export Sales Order for Import into HOFAKT ERP
 * Output as XML in var/export directory
 *
 * 20110722 ADD codepage conversion to CP850 because german special characters weren't imported correct to HOFAKT
 * 20110916 REMOVE WebSite Selector, activate SalesOrderExport for all Shops/Stores/Storeviews
 * 20111206 ADD attribute discount_Descr to Sales_Order XML as item-text
 * 20120111 CHANGE OrderId to realOrderId
 * 10120122 CHANGE DiscountAmount to netDiscountAmount
 * 20120210 ADD TAGS TaxVat Umsatzsteuer ID and Currency
 * 20120228 REMOVE netDiscount Calc
 * 20120424 ADD shippingMethod + productorderid for Cache
 */
class Adventos_OrderExport_Model_Observer
{
	/**
	 * Export Order
	 *
	 * @param Varien_Event_Observer $observer
	 */
	public function createOrderExport($observer)
	{
		if (Mage::getStoreConfig('catalog/orderexport/process')){
			try
			{
				/*
				 * Create Object with Orderdetails from Event
				 */
				$order = $observer->getEvent()->getOrder();
				// lookup for BSK Orders
				$storeId = $order->getStoreId();
				$webSite = Mage::getModel('core/store')->load($storeId)->getWebsiteId();

				$this->_exportOrder($order);
				Mage::log("ADVENTOS OrderExport done Store: ".$storeId);
			}
			catch (Exception $e)
			{
				Mage::logException($e);
			}
		}
	}

	/**
	 * write Orderdetails in XML-File
	 *
	 * @param Mage_Sales_Model_Order $order
	 * @return Adventos_OrderExport_Model_Observer
	 */
	protected function _exportOrder(Mage_Sales_Model_Order $order)
	{
		$ordArray = $this->createOrder($order);
		$ordXml = $this->toXml($ordArray,'salesOrder');
		$file = "SalesOrder_".$order->getId().".xml";

		$varExport = Mage::getBaseDir('export');
		$exportPath = $varExport.DS.$file;

		$handle = fopen($exportPath,"w+");
		fwrite($handle,$ordXml);
		fclose($handle);

		return $this;
	}

	public function toXML($data, $rootNodeName='base',$xml=null){
		if ($xml == null){
			/*$xml = simplexml_load_string("<?xml version='1.0' encoding='utf-8'?><$rootNodeName />");
			 * Change encoding from UTF-8 to CP850
			 */
			$xml = simplexml_load_string("<?xml version='1.0' encoding='cp850'?><$rootNodeName />");
		}

		// loop through the data passed in.

		foreach($data as $key => $value){
			// no numeric keys in our xml please!
			if (is_numeric($key)){
				// make string key...
				$key = "item_".$key;
			}
			// replace anything not alpha numeric
			$key = preg_replace('/[^a-z]/i', '', $key);
			// if there is another array found recrusively call this function

			if (is_array($value)){
				$node = $xml->addChild($key);
				// recrusive call.
				Adventos_OrderExport_Model_Observer::toXML($value, $rootNodeName, $node);
			} else {
				// add single node.
				$value = str_replace('€','EUR',$value);
				$xml->addChild($key,$value);
			}

		}

		// we want the XML to be formatted,
		// 20110722 add encoding CP850 for incredible stupid HOFAKT
		$doc = new DOMDocument('1.0', 'cp850');
		$doc->preserveWhiteSpace = false;
		$doc->loadXML( $xml->asXML() );
		$doc->formatOutput = true;
		return $doc->saveXML();

		//return $xml->asXML();

	}



	public function createOrder($order){

		$productArray = array();  // sale order line product wrapper

		// Magento required models
		$customer = Mage::getModel('customer/customer')->load($order->getCustomerId());

		// walk the sale order lines
		foreach ($order->getAllVisibleItems() as $item) //getAllItems() - getItemCollection()
		{
			$productArray[] = array(
				"product_sku" => $item->getSku(),
				"product_magento_id" => $item->getProductId(),
				"product_name" => $item->getName(),
				"product_qty" => $item->getQtyOrdered(),
				"product_price" => $item->getPrice(),
				"product_row_discount_amount" => $item->getDiscountAmount(),
				"product_row_price" => $item->getQtyOrdered() * $item->getPrice() - $item->getDiscountAmount(),
				"product_order_id" => $order->getRealOrderId(),
				"product_order_item_id" => $item->getId(),
			);
		}

		$streetBA = $order->getBillingAddress()->getStreet();
		$streetSA = $order->getShippingAddress()->getStreet();

		$customerGroupId = $order->getCustomerGroupId ();

		$group = Mage::getModel ('customer/group')->load ($customerGroupId);
		if ($group->getId()){
			$customerGroupName = $group->getCode();
		}

		if($customer->getEmail()=="") {
			$customerEmail = $order->getCustomerEmail();
		} else {
			$customerEmail = $customer->getEmail();
		}

		$saleorder = array(
			"id" => $order->getRealOrderId(),
			"store_id" => $order->getStoreId(),
			"store_name" => Mage::getModel('core/store')->load($order->getStoreID())->getName(),
			"hofakt_lager" => Mage::getStoreConfig('catalog/orderexport/storage_id'),
			"payment" => $order->getPayment()->getMethod(),
			"shipping_amount" => $order->getShippingAmount(),
			"discount_amount" => $order->getDiscountAmount(),
			"discount_descr" => $order->getDiscountDescription(),
			"net_total" => $order->getSubtotal(),
			"tax_amount" => $order->getTaxAmount(),
			"grand_total" => $order->getGrandTotal(),
			"currency" => $order->getOrderCurrencyCode(),
			"date" => $order->getCreatedAt(),
			"customer" => array(
				"customer_id" => $customer->getId(),
				"customer_name" => $customer->getName(),
				"customer_vatid" => $order->getCustomerTaxvat(),
				"customer_email" => $customerEmail,
				"customergroup" => $customerGroupName
		),
			"shipping_address" => array(
				"firstname" => $order->getShippingAddress()->getFirstname(),
				"lastname" => $order->getShippingAddress()->getLastname(),
				"company" => $order->getShippingAddress()->getCompany(),
				"street" => $streetSA[0],
				"street2" => (count($streetSA)==2)?$streetSA[1]:'',
				"city" => $order->getShippingAddress()->getCity(),
				"postcode" => $order->getShippingAddress()->getPostcode(),
				"country" => $order->getShippingAddress()->getCountry(),
				"phone" => $order->getShippingAddress()->getTelephone()
		),
			"billing_address" => array(
				"firstname" => $order->getBillingAddress()->getFirstname(),
				"lastname" => $order->getBillingAddress()->getLastname(),
				"company" => $order->getBillingAddress()->getCompany(),
				"street" => $streetBA[0],
				"street2" => (count($streetBA)==2)?$streetBA[1]:'',
				"city" => $order->getBillingAddress()->getCity(),
				"postcode" => $order->getBillingAddress()->getPostcode(),
				"country" => $order->getBillingAddress()->getCountry(),
				"phone" => $order->getBillingAddress()->getTelephone()
		),
			"lines" => $productArray,
		);

		return $saleorder;
	}
}