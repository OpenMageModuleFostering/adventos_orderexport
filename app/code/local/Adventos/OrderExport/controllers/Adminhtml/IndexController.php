<?php
class Adventos_OrderExport_Adminhtml_IndexController extends Mage_Adminhtml_Controller_Action {
	/**
	 * Export selected orders to XML files in order to be used by HOFAKT
	 */
	public function exportAction() {
		try {
			$orderIds = $this->getRequest()->getParam('order_ids');
			foreach($orderIds as $orderId) {
				$order = Mage::getModel('sales/order')->load($orderId);
				// Triggering this event causes oberver's createOrderExport method to be run
				Mage::dispatchEvent('adventos_orderexport_export_single_order' , array('order' => $order));
			}
			Mage::getSingleton('core/session')->addSuccess('Selected orders have been successfully exported');									
		}
		catch (Exception $e) {
			Mage::getSingleton('core/session')->addError('Error exporting orders. Details: '.$e->getMessage());
		}
		$this->_redirectReferer(); // return to the admin page
	}
}
?>