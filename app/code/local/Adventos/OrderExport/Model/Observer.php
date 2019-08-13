<?php
/**
 * @author ADVENTOS GmbH, Karsten Hoffmann
 * Export Sales Order for Import into HOFAKT ERP
 * Output as XML in var/export directory
 *
 *
 * 20110722 ADD codepage conversion to CP850 because german special characters weren't imported correct to HOFAKT
 * 20110916 REMOVE WebSite Selector, activate SalesOrderExport for all Shops/Stores/Storeviews
 * 20111206 ADD attribute discount_Descr to Sales_Order XML as item-text
 * 20120111 CHANGE OrderId to realOrderId
 * 10120122 CHANGE DiscountAmount to netDiscountAmount
 * 20120210 ADD TAGS TaxVat Umsatzsteuer ID and Currency
 * 20120228 REMOVE netDiscount Calc
 * 20121011 ADD generateCatalogInventoryFile
 * 20140820 CHANGE dispatchEvent to change Order_Status PENDING -> PROCESSING
 * 20140929 ADD support for bundle products, removing duplicated configurable products
 */
class Adventos_OrderExport_Model_Observer {
	/**
	 * Generate OrderExport
	 *
	 * @param Varien_Event_Observer $observer        	
	 */
	public function createOrderExport($observer) {
		Mage::log ( "ADVENTOS OrderExport Event - START" );
		if (Mage::getStoreConfig ( 'catalog/orderexport/process' )) {
			Mage::log ( "ADVENTOS OrderExport Event - Generate Export enable" );
			try {
				$order = $observer->getEvent ()->getOrder ();
				
				$storeId = $order->getStoreId ();
				$webSite = Mage::getModel ( 'core/store' )->load ( $storeId )->getWebsiteId ();
				
				$this->_exportOrder ( $order );
				Mage::log ( "ADVENTOS OrderExport Event - Store: " . $storeId );
			} catch ( Exception $e ) {
				Mage::logException ( $e );
			}
		} else {
			Mage::log ( "ADVENTOS OrderExport Event - Generate Export disable" );
		}
		Mage::log ( "ADVENTOS OrderExport Event - END" );
	}
	
	/**
	 * write OrderDetails in XML-File
	 *
	 * @param Mage_Sales_Model_Order $order        	
	 * @return Adventos_OrderExport_Model_Observer
	 */
	protected function _exportOrder(Mage_Sales_Model_Order $order) {
		$ordArray = $this->createOrder ( $order );
		$ordXml = $this->toXml ( $ordArray, 'salesOrder' );
		$file = "SalesOrder_" . $order->getId () . ".xml";
		
		$varExport = Mage::getBaseDir ( 'export' );
		
		if (Mage::getStoreConfig ( 'catalog/orderexport/export_path' ) != null) {
			if (Mage::getStoreConfig ( 'catalog/orderexport/export_path' ) != "") {
				$OrderExportPath = Mage::getStoreConfig ( 'catalog/orderexport/export_path' );
				if (! is_dir ( $varExport . DS . $OrderExportPath )) {
					Mage::log ( "ADVENTOS OrderExport Event - MultiShop ExportPath Folder not exist [" . $OrderExportPath . "]" );
					mkdir ( $varExport . DS . $OrderExportPath );
				}
				$file = $OrderExportPath . "/" . $file;
				Mage::log ( "ADVENTOS OrderExport Event - Add MultiShop ExportPath [" . $OrderExportPath . "]" );
			}
		}
		
		$exportPath = $varExport . DS . $file;
		
		$handle = fopen ( $exportPath, "w+" );
		fwrite ( $handle, $ordXml );
		fclose ( $handle );
		
		return $this;
	}
	public function _translateLiteral2NumericEntities($xmlSource, $reverse = FALSE) {
		static $literal2NumericEntity;
		
		if (empty ( $literal2NumericEntity )) {
			$transTbl = get_html_translation_table ( HTML_ENTITIES );
			foreach ( $transTbl as $char => $entity ) {
				if (strpos ( '&"<>', $char ) !== FALSE)
					continue;
				$literal2NumericEntity [$entity] = '&#' . ord ( $char ) . ';';
			}
		}
		if ($reverse) {
			return strtr ( $xmlSource, array_flip ( $literal2NumericEntity ) );
		} else {
			return strtr ( $xmlSource, $literal2NumericEntity );
		}
	}
	public function toXML($data, $rootNodeName = 'base', $xml = null) {
		if ($xml == null) {
			/*
			 * $xml = simplexml_load_string("<?xml version='1.0' encoding='utf-8'?><$rootNodeName />"); Change encoding from UTF-8 to CP850
			 */
			$xml = simplexml_load_string ( "<?xml version='1.0' encoding='cp850'?><$rootNodeName />" );
		}
		
		// loop through the data passed in.
		
		foreach ( $data as $key => $value ) {
			// no numeric keys in our xml please!
			if (is_numeric ( $key )) {
				// make item key for product array ...
				$key = "item"; // .$key;
			}
			// replace anything not alpha numeric
			$key = preg_replace ( '/[^a-z0-9]/i', '', $key );
			// if there is another array found recrusively call this function
			
			if (is_array ( $value )) {
				$node = $xml->addChild ( $key );
				// recrusive call.
				Adventos_OrderExport_Model_Observer::toXML ( $value, $rootNodeName, $node );
			} else {
				// add single node.
				$value = str_replace ( '€', 'EUR', $value );
				$value = htmlspecialchars ( $value );
				$xml->addChild ( $key, $value );
			}
		}
		
		// we want the XML to be formatted,
		// 20110722 add encoding CP850 for incredible stupid HOFAKT
		$doc = new DOMDocument ( '1.0', 'cp850' );
		$doc->preserveWhiteSpace = false;
		$doc->loadXML ( $xml->asXML () );
		$doc->formatOutput = true;
		return $doc->saveXML ();
	}
	public function createOrder($order) {
		$isPreviousProductConfigurable = false;
		$productArray = array (); // sale order line product wrapper
		                          
		// Magento required models
		$customer = Mage::getModel ( 'customer/customer' )->load ( $order->getCustomerId () );
		
		// walk the sale order lines
		foreach ( $order->getAllItems () as $item ) 		// getAllVisibleItems() - getAllItems() - getItemCollection()
		{
			// Check if Item is Bundled			
			if ($item->getHasChildren () && $item->getData ('product_type') == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE) {
				Mage::log ( "ADVENTOS Skip Item Type with children = " . $item->getData ('product_type'));				
			} else {
				
				// check if simple product has a configurable product. If yes, skip the product				
				if ($isPreviousProductConfigurable) {					
					if ($item->getData ('product_type') == Mage_Catalog_Model_Product_Type::TYPE_SIMPLE) {						
						$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($item->getData('product_id'));
						if (is_array($parentIds))
							if (isset($parentIds[0])) {
								$_product = Mage::getModel('catalog/product')->load($parentIds[0]);
								if ($_product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
									continue;
								}
							} 
						}
					}
 
					if ($item->getData ('product_type') == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE)
						$isPreviousProductConfigurable = true;
					else
						$isPreviousProductConfigurable = false;
						
				$tax_amount = $item->getTaxAmount ();
				$discount_percent = $item->getDiscountPercent ();
				$discountAmount = $item->getDiscountAmount ();
				
				// typo in discount_percent
				if ($discount_percent > 0) {
					$product_row_price = $item->getQtyOrdered () * $item->getOrginalPrice () - $item->getDiscountAmount ();
				} else {
					$product_row_price = $item->getQtyOrdered () * $item->getPrice () - $item->getDiscountAmount ();
					
					// when fixed amount per product calc discount percent value
					// rowDiscountPercent = (rowqty * rowproductprice) / rowdiscountamount
					
					if ($discountAmount > 0) {
						$discount_percent = round ( 100 * $discountAmount / ($item->getQtyOrdered () * $item->getPrice ()), 2 );
						if ($discount_percent == 100.00) {
							$discount_percent = 99.99;
						}
					}
				}

				
				$productArray [] = array (
					"product_sku" => $item->getSku (),
					"product_magento_id" => $item->getProductId (),
					"product_name" => $item->getName (),
					"product_qty" => $item->getQtyOrdered (),
					"product_price" => $item->getPrice (),
					"product_discount_percent" => $item->getDiscountPercent (),
					"product_row_discount_amount" => $item->getDiscountAmount (),
					"product_row_price" => $product_row_price,
					"product_order_id" => $order->getRealOrderId (),
					"product_order_item_id" => $item->getId (),
					"product_description" => ""
				);										
												
			}
		}
		
		$streetBA = $order->getBillingAddress ()->getStreet ();
		$streetSA = $order->getShippingAddress ()->getStreet ();
		
		$customerGroupId = $order->getCustomerGroupId ();
		$customerGroupName = "";
		
		$group = Mage::getModel ( 'customer/group' )->load ( $customerGroupId );
		if ($group->getId ()) {
			$customerGroupName = $group->getCode ();
		}
		
		if ($customer->getEmail () == "") {
			$customerEmail = $order->getCustomerEmail ();
		} else {
			$customerEmail = $customer->getEmail ();
		}
		
		$exportVatGroup = Mage::getStoreConfig ( 'catalog/orderexport/B2B_HomeGroup' );
		$exportVatGroup = explode ( ",", $exportVatGroup );
		
		if (in_array ( $customerGroupId, $exportVatGroup )) {
			$order->getBillingAddress ()->getvat_id () ? $billing_vat_id = $order->getBillingAddress ()->getCountry () . $order->getBillingAddress ()->getvat_id () : $billing_vat_id = "";
			$order->getShippingAddress ()->getvat_id () ? $shipping_vat_id = $order->getShippingAddress ()->getCountry () . $order->getShippingAddress ()->getvat_id () : $shipping_vat_id = "";
		} else {
			$billing_vat_id = "";
			$shipping_vat_id = "";
		}
		
		
		// Check if SalesOrder Fee is set
		if ($order->getFeeAmount () != null) {
			$saleorder = array (
					"id" => $order->getRealOrderId (),
					"store_id" => $order->getStoreId (),
					"store_name" => Mage::getModel ( 'core/store' )->load ( $order->getStoreID () )->getName (),
					"hofakt_lager" => Mage::getStoreConfig ( 'catalog/orderexport/storage_id' ),
					"hofakt_language" => Mage::getStoreConfig ( 'catalog/orderexport/store_language' ),
					"payment" => $order->getPayment ()->getMethod (),
					"shipping_amount" => $order->getShippingAmount (),
					"fee" => round ( $order->getFeeAmount (), 4 ),
					"discount_amount" => 0,
					"discount_descr" => $order->getDiscountDescription (),
					"net_total" => $order->getSubtotal (),
					"tax_amount" => $order->getTaxAmount (),
					"grand_total" => $order->getGrandTotal (),
					"currency" => $order->getOrderCurrencyCode (),
					"date" => $order->getCreatedAt (),
					"customer" => array (
							"customer_id" => $customer->getId (),
							"customer_name" => $customer->getName (),
							"customer_vatid" => $order->getCustomerTaxvat (),
							"customer_email" => $customerEmail,
							"customergroup" => $customerGroupName 
					),
					"shipping_address" => array (
							"firstname" => $order->getShippingAddress ()->getFirstname (),
							"lastname" => $order->getShippingAddress ()->getLastname (),
							"company" => $order->getShippingAddress ()->getCompany (),
							"street" => $streetSA [0],
							"street2" => (count ( $streetSA ) == 2) ? $streetSA [1] : '',
							"city" => $order->getShippingAddress ()->getCity (),
							"postcode" => $order->getShippingAddress ()->getPostcode (),
							"country" => $order->getShippingAddress ()->getCountry (),
							"versadr1" => $order->getShippingAddress ()->getRegionCode (),
							"phone" => $order->getShippingAddress ()->getTelephone (),
							"addressid" => $order->getShippingAddress ()->getCustomerAddressId (),
							"vatid" => $shipping_vat_id 
					),
					"billing_address" => array (
							"firstname" => $order->getBillingAddress ()->getFirstname (),
							"lastname" => $order->getBillingAddress ()->getLastname (),
							"company" => $order->getBillingAddress ()->getCompany (),
							"street" => $streetBA [0],
							"street2" => (count ( $streetBA ) == 2) ? $streetBA [1] : '',
							"city" => $order->getBillingAddress ()->getCity (),
							"postcode" => $order->getBillingAddress ()->getPostcode (),
							"country" => $order->getBillingAddress ()->getCountry (),
							"versadr1" => $order->getBillingAddress ()->getRegionCode (),
							"phone" => $order->getBillingAddress ()->getTelephone (),
							"addressid" => $order->getBillingAddress ()->getCustomerAddressId (),
							"vatid" => $billing_vat_id 
					),
					"lines" => $productArray 
			);
		} else {
			$saleorder = array (
					"id" => $order->getRealOrderId (),
					"store_id" => $order->getStoreId (),
					"store_name" => Mage::getModel ( 'core/store' )->load ( $order->getStoreID () )->getName (),
					"hofakt_lager" => Mage::getStoreConfig ( 'catalog/orderexport/storage_id' ),
					"hofakt_language" => Mage::getStoreConfig ( 'catalog/orderexport/store_language' ),
					"payment" => $order->getPayment ()->getMethod (),
					"shipping_amount" => $order->getShippingAmount (),
					"discount_amount" => 0,
					"discount_descr" => $order->getDiscountDescription (),
					"net_total" => $order->getSubtotal (),
					"tax_amount" => $order->getTaxAmount (),
					"grand_total" => $order->getGrandTotal (),
					"currency" => $order->getOrderCurrencyCode (),
					"date" => $order->getCreatedAt (),
					"customer" => array (
							"customer_id" => $customer->getId (),
							"customer_name" => $customer->getName (),
							"customer_vatid" => $order->getCustomerTaxvat (),
							"customer_email" => $customerEmail,
							"customergroup" => $customerGroupName 
					),
					"shipping_address" => array (
							"firstname" => $order->getShippingAddress ()->getFirstname (),
							"lastname" => $order->getShippingAddress ()->getLastname (),
							"company" => $order->getShippingAddress ()->getCompany (),
							"street" => $streetSA [0],
							"street2" => (count ( $streetSA ) == 2) ? $streetSA [1] : '',
							"city" => $order->getShippingAddress ()->getCity (),
							"postcode" => $order->getShippingAddress ()->getPostcode (),
							"country" => $order->getShippingAddress ()->getCountry (),
							"versadr1" => $order->getShippingAddress ()->getRegionCode (),
							"phone" => $order->getShippingAddress ()->getTelephone (),
							"addressid" => $order->getShippingAddress ()->getCustomerAddressId (),
							"vatid" => $shipping_vat_id 
					),
					"billing_address" => array (
							"firstname" => $order->getBillingAddress ()->getFirstname (),
							"lastname" => $order->getBillingAddress ()->getLastname (),
							"company" => $order->getBillingAddress ()->getCompany (),
							"street" => $streetBA [0],
							"street2" => (count ( $streetBA ) == 2) ? $streetBA [1] : '',
							"city" => $order->getBillingAddress ()->getCity (),
							"postcode" => $order->getBillingAddress ()->getPostcode (),
							"country" => $order->getBillingAddress ()->getCountry (),
							"versadr1" => $order->getBillingAddress ()->getRegionCode (),
							"phone" => $order->getBillingAddress ()->getTelephone (),
							"addressid" => $order->getBillingAddress ()->getCustomerAddressId (),
							"vatid" => $billing_vat_id 
					),
					"lines" => $productArray 
			);
		}
		return $saleorder;
	}
	public function generateCatalogInventoryFile($schedule) {
		Mage::log ( "ADVENTOS OrderExport CatalogInventoryExport - START" );
		try {
			$inventoryArray = array ();
			$collection = Mage::getModel ( 'catalog/product' )->getCollection ();
			foreach ( $collection->load () as $item ) {
				$inventoryArray [] = array (
						"sku" => $item->getSku (),
						"qty" => ( int ) Mage::getModel ( 'cataloginventory/stock_item' )->loadByProduct ( $item )->getQty () 
				);
			}
			$Xml = $this->toXml ( $inventoryArray, 'catalogInventory' );
			$file = "CatalogInventoryExport.xml";
			$varExport = Mage::getBaseDir ( 'export' );
		} catch ( Exception $e ) {
			Mage::logException ( $e );
		}
		
		$web_exported = @array ();
		$allStores = Mage::app ()->getStores ();
		foreach ( $allStores as $_eachStoreId => $val ) {
			$exportXml = $Xml;
			$exportFile = $file;
			$_storeId = Mage::app ()->getStore ( $_eachStoreId )->getId ();
			$_webId = Mage::app ()->getStore ( $_eachStoreId )->getWebsiteId ();
			$app = Mage::app ()->setCurrentStore ( $_storeId );
			if (Mage::getStoreConfig ( 'catalog/orderexport/process' )) {
				if (! in_array ( $_webId, $web_exported )) {
					if (Mage::getStoreConfig ( 'catalog/orderexport/export_path' ) != null) {
						if (Mage::getStoreConfig ( 'catalog/orderexport/export_path' ) != "") {
							$orderExportPath = Mage::getStoreConfig ( 'catalog/orderexport/export_path' );
							if (! is_dir ( $varExport . DS . $orderExportPath )) {
								mkdir ( $varExport . DS . $orderExportPath );
								Mage::log ( "ADVENTOS OrderExport Event - Add MultiShop ExportPath [" . $orderExportPath . "]" );
							}
							$exportFile = $orderExportPath . "/" . $exportFile;
						}
					}
					$exportPath = $varExport . DS . $exportFile;
					$handle = fopen ( $exportPath, "w+" );
					fwrite ( $handle, $exportXml );
					fclose ( $handle );
					Mage::log ( "ADVENTOS OrderExport CatalogInventoryExport - [" . $orderExportPath . "] Done" );
				}
				array_push ( $web_exported, $_webId );
			}
		}
		Mage::log ( "ADVENTOS OrderExport CatalogInventoryExport - END" );
	}
	
	/**
	 * Adds the "Export to HOFAKT" item to the drop down in admin > orders
	 * @param object $observer
	 */
	public function addExportMassAction($observer) {
		$block = $observer->getEvent()->getBlock();
		// Check if this block is a MassAction block
		if ($block instanceof Mage_Adminhtml_Block_Widget_Grid_Massaction) {
			// Check if we're dealing with the Orders grid
			if ($block->getParentBlock() instanceof Mage_Adminhtml_Block_Sales_Order_Grid) {
				// The first parameter has to be unique, or you'll overwrite the old action.
				$block->addItem('orderexport', array(
						'label' => 'Export to HOFAKT',
						'url' => $block->getUrl('order_export/adminhtml_index/export'),
				)
				);
			}
		}
		
	}
	
	/**
	 * Exports the order when the status of the order is updated from "pending" to processing
	 * 
	 * @param object $observer
	 */	
	public function exportOnStatusChange($observer) {
		
		$order = $observer->getEvent()->getOrder();
		
		$oldStatus = $order->getOrigData('state');
		$newStatus = $order->getState();
		Mage::Log("==== Status Change Begin ===");
		Mage::Log("Old Status: ".$oldStatus);
		Mage::Log("New Status: ".$newStatus);
		
		if ($oldStatus == Mage_Sales_Model_Order::STATE_NEW && $newStatus == Mage_Sales_Model_Order::STATE_PROCESSING) {
			Mage::dispatchEvent('adventos_orderexport_export_single_order' , array('order' => $order));
			Mage::Log("==== Export Event Fired ===");
		}
		
	}
	
	/**
	 * Exports the order when a new order has been received and its status is "processing"
	 * 
	 * @param object $observer
	 */
	public function exportNewProcessingOrder($observer) {
		$order = $observer->getEvent()->getOrder();
		Mage::log("an order has been received. Order status is: ".$order->getStatus());
		if ($order->getStatus() == Mage_Sales_Model_Order::STATE_PROCESSING) {
			Mage::dispatchEvent('adventos_orderexport_export_single_order' , array('order' => $order));
			Mage::Log("==== Export Event Fired ===");				
		}
		
	}
	
	
}