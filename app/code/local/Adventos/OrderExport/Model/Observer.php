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
 * 20121011 ADD generateCatalogInventoryFile
 */
class Adventos_OrderExport_Model_Observer
{
	/**
	 * Generate OrderExport
	 *
	 * @param Varien_Event_Observer $observer
	 */
	public function createOrderExport($observer)
	{
		Mage::log("ADVENTOS OrderExport Event - START");
		if (Mage::getStoreConfig('catalog/orderexport/process')){
			Mage::log("ADVENTOS OrderExport Event - Generate Export enable");
			try
			{
				$order = $observer->getEvent()->getOrder();

				$storeId = $order->getStoreId();
				$webSite = Mage::getModel('core/store')->load($storeId)->getWebsiteId();

				$this->_exportOrder($order);
				Mage::log("ADVENTOS OrderExport Event - Store: ".$storeId);
			}
			catch (Exception $e)
			{
				Mage::logException($e);
			}
		}else{
			Mage::log("ADVENTOS OrderExport Event - Generate Export disable");
		}
		Mage::log("ADVENTOS OrderExport Event - END");
	}



	/**
	 * write OrderDetails in XML-File
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
		
		if (Mage::getStoreConfig('catalog/orderexport/export_path') != null){
			if (Mage::getStoreConfig('catalog/orderexport/export_path') != ""){
				$OrderExportPath = Mage::getStoreConfig('catalog/orderexport/export_path');
				if(!is_dir($varExport.DS.$OrderExportPath)){
					Mage::log("ADVENTOS OrderExport Event - MultiShop ExportPath Folder not exist [".$OrderExportPath."]");
					mkdir($varExport.DS.$OrderExportPath);
				}
				$file = $OrderExportPath."/".$file;
				Mage::log("ADVENTOS OrderExport Event - Add MultiShop ExportPath [".$OrderExportPath."]");
			}
		}

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
				// make item key for product array ...
				$key = "item"; //.$key;
			}
			// replace anything not alpha numeric
			$key = preg_replace('/[^a-z0-9]/i', '', $key);
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
		//20110722 add encoding CP850 for incredible stupid HOFAKT
		$doc = new DOMDocument('1.0', 'cp850');
		$doc->preserveWhiteSpace = false;
		$doc->loadXML( $xml->asXML() );
		$doc->formatOutput = true;
		return $doc->saveXML();
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

		if($customer->getEmail() == "") {
			$customerEmail = $order->getCustomerEmail();
		} else {
			$customerEmail = $customer->getEmail();
		}

		$saleorder = array(
				"id" => $order->getRealOrderId(),
				"store_id" => $order->getStoreId(),
				"store_name" => Mage::getModel('core/store')->load($order->getStoreID())->getName(),
				"hofakt_lager" => Mage::getStoreConfig('catalog/orderexport/storage_id'),
				"hofakt_language" => Mage::getStoreConfig('catalog/orderexport/store_language'),
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

	public function generateCatalogInventoryFile($schedule){
		Mage::log("ADVENTOS OrderExport CatalogInventoryExport - START");
		try
		{
			$inventoryArray = array();
			$collection = Mage::getModel('catalog/product')->getCollection();
			foreach ($collection->load() as $item)
			{
				$inventoryArray[] = array(
					"sku" => $item->getSku(),
					"qty" => (int)Mage::getModel('cataloginventory/stock_item')->loadByProduct($item)->getQty(),
				);
			}
			$Xml = $this->toXml($inventoryArray,'catalogInventory');
			$file = "CatalogInventoryExport.xml";
			$varExport = Mage::getBaseDir('export');
		}
		catch (Exception $e)
		{
			Mage::logException($e);
		}
		
		$web_exported = @array();
		$allStores = Mage::app()->getStores();
		foreach ($allStores as $_eachStoreId => $val) {
			$exportXml = $Xml;
			$exportFile = $file;
			$_storeId = Mage::app()->getStore($_eachStoreId)->getId();
			$_webId = Mage::app()->getStore($_eachStoreId)->getWebsiteId();
			$app = Mage::app()->setCurrentStore($_storeId);
			if (Mage::getStoreConfig('catalog/orderexport/process')){
				if (!in_array($_webId, $web_exported)){
					if (Mage::getStoreConfig('catalog/orderexport/export_path') != null){
						if (Mage::getStoreConfig('catalog/orderexport/export_path') != ""){
							$orderExportPath = Mage::getStoreConfig('catalog/orderexport/export_path');
							if(!is_dir($varExport.DS.$orderExportPath)){
								mkdir($varExport.DS.$orderExportPath);
								Mage::log("ADVENTOS OrderExport Event - Add MultiShop ExportPath [".$orderExportPath."]");
							}
							$exportFile = $orderExportPath."/".$exportFile;
						}
					}
					$exportPath = $varExport.DS.$exportFile;
					$handle = fopen($exportPath,"w+");
					fwrite($handle,$exportXml);
					fclose($handle);
					Mage::log("ADVENTOS OrderExport CatalogInventoryExport - [".$orderExportPath."] Done");
				}
				array_push($web_exported, $_webId);
			}
		}
		Mage::log("ADVENTOS OrderExport CatalogInventoryExport - END");
	}
}