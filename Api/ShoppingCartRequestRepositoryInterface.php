<?php
namespace Osiyatech\ShoppingCart\Api;

use Osiyatech\ShoppingCart\Api\Data\ShoppingCartRequestInterface;

interface ShoppingCartRequestRepositoryInterface
{
    /**
     * @param ShoppingCartRequestInterface $request
     * @return ShoppingCartRequestInterface
     */
    public function save(ShoppingCartRequestInterface $request);

    /**
     * @param int $id
     * @return ShoppingCartRequestInterface
     */
    public function getById($id);

    /**
     * @param int $quoteId
     * @return ShoppingCartRequestInterface|null
     */
    public function getByQuoteId($quoteId);

    /**
     * @param int $quoteId
     * @param string $requestType
     * @return ShoppingCartRequestInterface|null
     */
    public function getByQuoteIdAndType($quoteId, $requestType);

    /**
     * @param string $resumeToken
     * @return ShoppingCartRequestInterface|null
     */
    public function getByResumeToken($resumeToken);

    /**
     * @param string $approvalToken
     * @return ShoppingCartRequestInterface|null
     */
    public function getByApprovalToken($approvalToken);

    /**
     * Get latest approved request by quote and customer.
     *
     * @param int $quoteId
     * @param int $customerId
     * @return ShoppingCartRequestInterface|null
     */
    public function getApprovedRequestForQuoteCustomer($quoteId, $customerId);
}
