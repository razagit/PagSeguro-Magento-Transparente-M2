<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace RicardoMartins\PagSeguro\Model;

class Notifications extends \Magento\Payment\Model\Method\AbstractMethod
{
    
    /**
     * PagSeguro Helper
     *
     * @var RicardoMartins\PagSeguro\Helper\Data;
     */ 
    protected $pagSeguroHelper;

    /**
     * Magento Sales Order Model
     *
     * @var \Magento\Sales\Model\Order
     */ 
    protected $orderModel;

     /**
     * Magento transaction Factory
     *
     * @var \Magento\Framework\DB\Transaction
     */ 
    protected $transactionFactory;


    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \RicardoMartins\PagSeguro\Helper\Data $pagSeguroHelper,
        \Magento\Sales\Api\Data\OrderInterface $orderModel,
        \Magento\Framework\DB\Transaction $transactionFactory,
        array $data = array()
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            null,
            null,
            $data
        );

        $this->pagSeguroHelper = $pagSeguroHelper;  
        $this->orderModel = $orderModel;  
        $this->transactionFactory = $transactionFactory;  
    }

  
    /**
     * Processes notification XML data. XML is sent right after order is sent to PagSeguro, and on order updates.
     * @param SimpleXMLElement $resultXML
     */
    public function proccessNotificatonResult($resultXML)
    {
        if (isset($resultXML->error)) {
            $errMsg = __((string)$resultXML->error->message);
            throw new \Magento\Framework\Validator\Exception(
              __(
                    'Problemas ao processar seu pagamento. %s(%s)',
                    $errMsg,
                    (string)$resultXML->error->code
                )
            );
        }
        if (isset($resultXML->reference)) {
            $orderNo = (string)$resultXML->reference;
            $order = $this->orderModel->loadByIncrementId($orderNo);
            if (!$order->getId()) {
                $this->pagSeguroHelper->writeLog(
                    sprintf('Request %s not found on system. Unable to process return.', $orderNo)
                );
                return $this;
            }
            $payment = $order->getPayment();

            $this->_code = $payment->getMethod();
            $processedState = $this->processStatus((int)$resultXML->status);

            $message = $processedState->getMessage();

            if ((int)$resultXML->status == 6) { //valor devolvido (gera credit memo e tenta cancelar o pedido)
                if ($order->canUnhold()) {
                    $order->unhold();
                }

                if ($order->canCancel()) {
                    $order->cancel();
                    $order->save();
                } else {
                    $payment->registerRefundNotification(floatval($resultXML->grossAmount));
                    $order->addStatusHistoryComment(
                        'Returned: Amount returned to buyer.'
                    )->save();
                }
            }

            if ((int)$resultXML->status == 7 && isset($resultXML->cancellationSource)) {
                //Especificamos a fonte do cancelamento do pedido
                switch((string)$resultXML->cancellationSource)
                {
                    case 'INTERNAL':
                        $message .= ' PagSeguro itself denied or canceled the transaction.';
                        break;
                    case 'EXTERNAL':
                        $message .= 'The transaction was denied or canceled by the bank.';
                        break;
                }
                $order->cancel();
            }

            if ($processedState->getStateChanged()) {
                // somente para o status 6 que edita o status do pedido - Weber
                if ((int)$resultXML->status != 6) {
                    $order->setState(
                        $processedState->getState(),
                        true,
                        $message,
                        $processedState->getIsCustomerNotified()
                    )->save();
                }

            } else {
                $order->addStatusHistoryComment($message);
            }

            if ((int)$resultXML->status == 3) { 
                if(!$order->hasInvoices()){
                    $invoice = $order->prepareInvoice();
                    $invoice->register()->pay();
                    $msg = sprintf('Captured payment. Transaction Identifier: %s', (string)$resultXML->code);
                    $invoice->addComment($msg);
                    $invoice->sendEmail(
                        $this->pagSeguroHelper->getStoreConfigValue('payment/rm_pagseguro/send_invoice_email'),
                        'Payment received successfully.'
                    );

                    // salva o transaction id na invoice
                    if (isset($resultXML->code)) {
                        $invoice->setTransactionId((string)$resultXML->code)->save();
                    }

                    $this->transactionFactory->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->save();
                    $order->addStatusHistoryComment(sprintf('Invoice # %s successfully created.', $invoice->getIncrementId()));
                }
            }

            $payment->save();
            $order->save();
        } else {
            throw new \Magento\Framework\Validator\Exception(__('Invalid return. Order reference not found.'));
        }
    }


    /**
     * @param $notificationCode
     * @return SimpleXMLElement
     */
    public function getNotificationStatus($notificationCode)
    {
        $url = "https://ws.pagseguro.uol.com.br/v2/transactions/notifications/";

        $params = array('token' => $this->pagSeguroHelper->getToken(), 'email' => $this->pagSeguroHelper->getMerchantEmail(),);
        $url .= '?' . http_build_query($params);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        try {
            $return = curl_exec($ch);
        } catch (Exception $e) {
            $this->pagSeguroHelper->writeLog(
                sprintf('Failed to catch return for notificationCode %s: %s(%d)', $notificationCode, curl_error($ch),
                    curl_errno($ch)
                )
            );
        }

        $this->pagSeguroHelper->writeLog(sprintf('Return of the Pagseguro to notificationCode %s: %s', $notificationCode, $return));

        libxml_use_internal_errors(true);
        $xml = \SimpleXML_Load_String(trim($return));
        if (false === $xml) {
            $this->pagSeguroHelper->writeLog('Return XML notification PagSeguro in unexpected format. Return: ' . $return);
        }

        curl_close($ch);
        return $xml;
    }


     /**
     * Processes order status and return information about order status
     * @param $statusCode
     * @return Object
     */
    public function processStatus($statusCode)
    {
        $return = new \Magento\Framework\DataObject();
        $return->setStateChanged(true);
        $return->setIsTransactionPending(true); //payment is pending?

        switch($statusCode)
        {
            case '1':
                $return->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                $return->setIsCustomerNotified($this->getCode()!='pagseguro_cc');
                if ($this->getCode()=='rm_pagseguro_cc') {
                    $return->setStateChanged(false);
                }
                $return->setMessage(
                    'Awaiting payment: the buyer initiated the transaction, but so far PagSeguro has not received any payment information.'
                );
                break;
            case '2':
                $return->setState(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW);
                $return->setIsCustomerNotified(true);
                $return->setMessage('Under review: the buyer chose to pay with a credit card and
                    PagSeguro is analyzing the risk of the transaction.'
                );
                break;
            case '3':
                $return->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                $return->setIsCustomerNotified(true);
                $return->setMessage('Pay: the transaction was paid by the buyer and PagSeguro has already received a confirmation
                    of the financial institution responsible for processing.'
                );
                $return->setIsTransactionPending(false);
                break;
            case '4':
                $return->setMessage('Available: The transaction has been paid and has reached the end of its
                    has been returned and there is no open dispute'
                );
                $return->setIsCustomerNotified(false);
                $return->setStateChanged(false);
                $return->setIsTransactionPending(false);
                break;
            case '5':
                $return->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                $return->setIsCustomerNotified(false);
                $return->setIsTransactionPending(false);
                $return->setMessage('In dispute: the buyer, within the term of release of the transaction,
                    opened a dispute.'
                );
                break;
            case '6':
                $return->setData('state', \Magento\Sales\Model\Order::STATE_CLOSED);
                $return->setIsCustomerNotified(false);
                $return->setIsTransactionPending(false);
                $return->setMessage('Returned: The transaction amount was returned to the buyer.');
                break;
            case '7':
                $return->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
                $return->setIsCustomerNotified(true);
                $return->setMessage('Canceled: The transaction was canceled without being finalized.');
                break;
            default:
                $return->setIsCustomerNotified(false);
                $return->setStateChanged(false);
                $return->setMessage('Invalid status code returned by PagSeguro. (' . $statusCode . ')');
        }
        return $return;
    }

}
