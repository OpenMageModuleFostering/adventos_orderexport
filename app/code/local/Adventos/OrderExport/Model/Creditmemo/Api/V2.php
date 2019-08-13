<?php
class Adventos_OrderExport_Model_Creditmemo_Api_V2 extends Adventos_OrderExport_Model_Creditmemo_Api
{

	/**
	 * Initialize attributes' mapping
	 */
	public function __construct()
	{
		$this->_attributesMap['creditmemo'] = array(
				'creditmemo_id' => 'entity_id'
		);
		$this->_attributesMap['creditmemo_item'] = array(
				'item_id'    => 'entity_id'
		);
		$this->_attributesMap['creditmemo_comment'] = array(
				'comment_id' => 'entity_id'
		);
	}

	/**
	 * Retrieve credit memos by filters
	 *
	 * @param array|null $filter
	 * @return array
	 */
	public function items($filter = null)
	{
		$filter = $this->_prepareListFilter($filter);
		try {
			$result = array();
			/** @var $creditmemoModel Mage_Sales_Model_Order_Creditmemo */
			$creditmemoModel = Mage::getModel('sales/order_creditmemo');
			// map field name entity_id to creditmemo_id
			foreach ($creditmemoModel->getFilteredCollectionItems($filter) as $creditmemo) {
				$result[] = $this->_getAttributes($creditmemo, 'creditmemo');
			}
		} catch (Exception $e) {
			$this->_fault('invalid_filter', $e->getMessage());
		}
		return $result;
	}

	/**
	 * Prepare filters
	 *
	 * @param null|object $filters
	 * @return array
	 */
	protected function _prepareListFilter($filters = null)
	{
		$preparedFilters = array();
		$helper = Mage::helper('api');
		if (isset($filters->filter)) {
			$helper->associativeArrayUnpack($filters->filter);
			$preparedFilters += $filters->filter;
		}
		if (isset($filters->complex_filter)) {
			$helper->associativeArrayUnpack($filters->complex_filter);
			foreach ($filters->complex_filter as &$filter) {
				$helper->associativeArrayUnpack($filter);
			}
			$preparedFilters += $filters->complex_filter;
		}
		foreach ($preparedFilters as $field => $value) {
			if (isset($this->_attributesMap['creditmemo'][$field])) {
				$preparedFilters[$this->_attributesMap['creditmemo'][$field]] = $value;
				unset($preparedFilters[$field]);
			}
		}

		return $preparedFilters;
	}

	/**
	 * Create new credit memo for order
	 *
	 * @param string $orderId
	 * @param array $data array('qtys' => array('sku1' => qty1, ... , 'skuN' => qtyN),
	 *      'shipping_amount' => value, 'adjustment_positive' => value, 'adjustment_negative' => value)
	 * @param string $refundToStoreCreditAmount
	 * @param string|null $comment
	 * @param bool $notifyCustomer
	 * @param bool $includeComment
	 * @param string $refundToStoreCreditAmount
	 * @return string $creditmemoId
	 */
	public function create($orderId, $data = null,  $creditmemoNr = null, $comment = null, $notifyCustomer = false,
			$includeComment = false, $refundToStoreCreditAmount = null)
	{
		/** @var $order Mage_Sales_Model_Order */
		$order = Mage::getModel('sales/order')->load($orderId, 'increment_id');
		if (!$order->getId()) {
			$this->_fault('order_not_exists');
		}
		if (!$order->canCreditmemo()) {
			$this->_fault('cannot_create_creditmemo');
		}
		$data = $this->_prepareCreateData($data);

		/** @var $service Mage_Sales_Model_Service_Order */
		$service = Mage::getModel('sales/service_order', $order);
		/** @var $creditmemo Mage_Sales_Model_Order_Creditmemo */
		$creditmemo = $service->prepareCreditmemo($data);

		if ($creditmemoNr !== null) {
			$creditmemo->setIncrementId($creditmemoNr);
		}

		// refund to Store Credit
		if ($refundToStoreCreditAmount) {
			// check if refund to Store Credit is available
			if ($order->getCustomerIsGuest()) {
				$this->_fault('cannot_refund_to_storecredit');
			}
			$refundToStoreCreditAmount = max(
					0,
					min($creditmemo->getBaseCustomerBalanceReturnMax(), $refundToStoreCreditAmount)
			);
			if ($refundToStoreCreditAmount) {
				$refundToStoreCreditAmount = $creditmemo->getStore()->roundPrice($refundToStoreCreditAmount);
				$creditmemo->setBaseCustomerBalanceTotalRefunded($refundToStoreCreditAmount);
				$refundToStoreCreditAmount = $creditmemo->getStore()->roundPrice(
						$refundToStoreCreditAmount*$order->getStoreToOrderRate()
				);
				// this field can be used by customer balance observer
				$creditmemo->setBsCustomerBalTotalRefunded($refundToStoreCreditAmount);
				// setting flag to make actual refund to customer balance after credit memo save
				$creditmemo->setCustomerBalanceRefundFlag(true);
			}
		}
		$creditmemo->setPaymentRefundDisallowed(true)->register();
		// add comment to creditmemo
		if (!empty($comment)) {
			$creditmemo->addComment($comment, $notifyCustomer);
		}
		try {
			Mage::getModel('core/resource_transaction')
			->addObject($creditmemo)
			->addObject($order)
			->save();
			// send email notification
			$creditmemo->sendEmail($notifyCustomer, ($includeComment ? $comment : ''));
		} catch (Mage_Core_Exception $e) {
			$this->_fault('data_invalid', $e->getMessage());
		}
		return $creditmemo->getIncrementId();
	}

	/**
	 * Prepare data
	 *
	 * @param null|object $data
	 * @return array
	 */
	protected function _prepareCreateData($data)
	{
		// convert data object to array, if it's null turn it into empty array
		$data = (isset($data) and is_object($data)) ? get_object_vars($data) : array();
		// convert qtys object to array
		if (isset($data['qtys']) && count($data['qtys'])) {
			$qtysArray = array();
			foreach ($data['qtys'] as &$item) {
				if (isset($item->order_item_id) && isset($item->qty)) {
					$qtysArray[$item->order_item_id] = $item->qty;
				}
			}
			$data['qtys'] = $qtysArray;
		}
		return $data;
	}


	/**
	 * Load CreditMemo by IncrementId
	 *
	 * @param mixed $incrementId
	 * @return Mage_Core_Model_Abstract|Mage_Sales_Model_Order_Creditmemo
	 */
	protected function _getCreditmemo($incrementId)
	{
		/** @var $creditmemo Mage_Sales_Model_Order_Creditmemo */
		$creditmemo = Mage::getModel('sales/order_creditmemo')->load($incrementId, 'increment_id');
		if (!$creditmemo->getId()) {
			$this->_fault('not_exists');
		}
		return $creditmemo;
	}
}
?>