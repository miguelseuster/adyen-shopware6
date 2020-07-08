<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentMethodsService
{
    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var Currency
     */
    private $currency;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * PaymentMethodsService constructor.
     * @param EntityRepositoryInterface $salesChannelRepository
     * @param LoggerInterface $logger
     * @param CheckoutService $checkoutService
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     * @param CartService $cartService
     */
    public function __construct(
        EntityRepositoryInterface $salesChannelRepository,
        LoggerInterface $logger,
        CheckoutService $checkoutService,
        ConfigurationService $configurationService,
        Currency $currency,
        CartService $cartService
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->checkoutService = $checkoutService;
        $this->configurationService = $configurationService;
        $this->currency = $currency;
        $this->cartService = $cartService;
        $this->logger = $logger;
    }

    /**
     * @param SalesChannelContext $context
     * @return array
     */
    public function getPaymentMethods(SalesChannelContext $context): array
    {
        $responseData = [];
        try {
            $requestData = $this->buildPaymentMethodsRequestData($context);
            if (!empty($requestData)) {
                $responseData = $this->checkoutService->paymentMethods($requestData);
            }
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
        }
        return $responseData;
    }

    /**
     * @param SalesChannelContext $context
     * @return array
     */
    private function buildPaymentMethodsRequestData(SalesChannelContext $context)
    {
        if (is_null($context->getCustomer())) {
            return [];
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $merchantAccount = $this->configurationService->getMerchantAccount();

        if (!$merchantAccount) {
            $this->logger->error('No Merchant Account has been configured. ' .
                'Go to the Adyen plugin configuration panel and finish the required setup.');
            return [];
        }

        $salesChannelCriteria = new Criteria([$context->getSalesChannel()->getId()]);
        $salesChannel = $this->salesChannelRepository->search(
            $salesChannelCriteria->addAssociation('language.locale'),
            Context::createDefaultContext())->first();
        $shopperLocale = $salesChannel->getLanguage()->getLocale()->getCode();


        $currency = $context->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize($cart->getPrice()->getTotalPrice(), $currency);
        if ($context->getCustomer()->getActiveBillingAddress()->getCountry()->getIso()) {
            $countryCode = $context->getCustomer()->getActiveBillingAddress()->getCountry()->getIso();
        } else {
            $countryCode = $context->getCustomer()->getActiveShippingAddress()->getCountry()->getIso();
        }
        $shopperReference = $context->getCustomer()->getId();

        $requestData = array(
            "channel" => "Web",
            "merchantAccount" => $merchantAccount,
            "countryCode" => $countryCode,
            "amount" => array(
                "currency" => $currency,
                "value" => $amount
            ),
            "shopperReference" => $shopperReference,
            "shopperLocale" => $shopperLocale
        );

        return $requestData;
    }
}