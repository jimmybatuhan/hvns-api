<?php

namespace App\ZAP;

class Constants
{
    public const ZAP_REWARD_PECENTAGE = 0.02; // 2%

    public const DISCOUNT_PREFIX = 'ZAP_POINTS';

    public const NOT_FOUND = '404-00';
    public const EMAIL_ALREADY_EXISTS = '409-03';
    public const MOBILE_ALREADY_EXISTS = '400-04';

    public const TRANSACTION_STATUS_CLEARED = 'cleared';
    public const TRANSACTION_STATUS_VOIDED = 'voided';

    public const MEMBER_NAMESPACE = 'zap_member';
    public const TRANSACTION_NAMESPACE = 'zap_transaction';

    public const TRANSACTION_POINTS_KEY = 'calculated_points';
    public const MEMBER_ID_KEY = 'zap_member_id';
    public const TRANSACTION_REFERENCE_KEY = 'reference_no';
    public const TRANSACTION_STATUS_KEY = 'status';
}