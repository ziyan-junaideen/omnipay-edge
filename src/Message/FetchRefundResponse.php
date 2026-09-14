<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * A refund demand read by fetchRefund(). isSuccessful() means refunded: only a
 * `succeeded` refund. `pending` and `processing` are isPending().
 */
class FetchRefundResponse extends AbstractRefundResponse
{
}
