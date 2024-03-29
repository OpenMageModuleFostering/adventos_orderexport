<?php
class Adventos_OrderExport_Model_Invoice_Api_V2 extends Adventos_OrderExport_Model_Invoice_Api
{
	/**
	 * Retrive invoices by filters
	 *
	 * @param array $filters
	 * @return array
	 */
	public function items($filters = null)
	{
		//TODO: add full name logic
		$collection =  Mage::getModel('sales/order_invoice')->getCollection()
		->addAttributeToSelect('order_id')
		->addAttributeToSelect('increment_id')
		->addAttributeToSelect('created_at')
		->addAttributeToSelect('state')
		->addAttributeToSelect('grand_total')
		->addAttributeToSelect('order_currency_code')
		->joinAttribute('billing_firstname', 'order_address/firstname', 'billing_address_id', null, 'left')
		->joinAttribute('billing_lastname', 'order_address/lastname', 'billing_address_id', null, 'left')
		->joinAttribute('order_increment_id', 'order/increment_id', 'order_id', null, 'left')
		->joinAttribute('order_created_at', 'order/created_at', 'order_id', null, 'left');

		$preparedFilters = array();
		if (isset($filters->filter)) {
			foreach ($filters->filter as $_filter) {
				$preparedFilters[][$_filter->key] = $_filter->value;
			}
		}
		if (isset($filters->complex_filter)) {
			foreach ($filters->complex_filter as $_filter) {
				$_value = $_filter->value;
				if(is_object($_value)) {
					$preparedFilters[][$_filter->key] = array(
							$_value->key => $_value->value
					);
				} elseif(is_array($_value)) {
					$preparedFilters[][$_filter->key] = array(
							$_value['key'] => $_value['value']
					);
				} else {
					$preparedFilters[][$_filter->key] = $_value;
				}
			}
		}

		if (!empty($preparedFilters)) {
			try {
				foreach ($preparedFilters as $preparedFilter) {
					foreach ($preparedFilter as $field => $value) {
						if (isset($this->_attributesMap['order'][$field])) {
							$field = $this->_attributesMap['order'][$field];
						}

						$collection->addFieldToFilter($field, $value);
					}
				}
			} catch (Mage_Core_Exception $e) {
				$this->_fault('filters_invalid', $e->getMessage());
			}
		}

		$result = array();

		foreach ($collection as $invoice) {
			$result[] = $this->_getAttributes($invoice, 'invoice');
		}

		return $result;
	}

	protected function _prepareItemQtyData($data)
	{
		$_data = array();
		foreach ($data as $item) {
			if (isset($item->order_item_id) && isset($item->qty)) {
				$_data[$item->order_item_id] = $item->qty;
			}
		}
		return $_data;
	}

	/* Create new invoice for order
	 *
	* @param string $orderIncrementId
	* @param array $itemsQty
	* @param string $invoiceNr
	* @param string $comment
	* @param booleam $email
	* @param boolean $includeComment
	* @return string
	* public function invoiceCreate($orderIncrementId, $invoiceNr, $itemsQty, $comment = null, $email = false, $includeComment = false)
	*/
	public function create($orderIncrementId, $itemsQty, $invoiceNr = null, $comment = null, $email = false, $includeComment = false)
	{
		$order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
		$itemsQty = $this->_prepareItemQtyData($itemsQty);
		/* @var $order Mage_Sales_Model_Order */
		/**
		 * Check order existing
		*/
		if (!$order->getId()) {
			$this->_fault('order_not_exists');
		}

		/**
		 * Check invoice create availability
		 */
		if (!$order->canInvoice()) {
			$this->_fault('data_invalid', Mage::helper('sales')->__('Cannot do invoice for order.'));
		}

		$invoice = $order->prepareInvoice($itemsQty);

		$invoice->register();

		if ($comment !== null) {
			$invoice->addComment($comment, $email);
		}

		if ($email) {
			$invoice->setEmailSent(true);
		}

		if ($invoiceNr !== null) {
			$invoice->setIncrementId($invoiceNr);
		}

		$invoice->getOrder()->setIsInProcess(true);

		try {
			$transactionSave = Mage::getModel('core/resource_transaction')
			->addObject($invoice)
			->addObject($invoice->getOrder())
			->save();

			$invoice->sendEmail($email, ($includeComment ? $comment : ''));
		} catch (Mage_Core_Exception $e) {
			$this->_fault('data_invalid', $e->getMessage());
		}

		return $invoice->getIncrementId();
	}
}
?>