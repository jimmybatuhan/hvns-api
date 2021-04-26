<?php

namespace App\ZAP;

class Constants
{
    public const DISCOUNT_PREFIX = 'ZAP_POINTS';
    public const EMAIL_ALREADY_EXISTS = '409-03';
    public const MEMBER_ID_KEY = 'zap_member_id';
    public const MEMBER_NAMESPACE = 'zap_member';
    public const MEMBER_POINTS_KEY = 'member_total_points';
    public const MOBILE_ALREADY_EXISTS = '400-04';
    public const NOT_FOUND = '404-00';
    public const LAST_TRANSACTION_KEY = 'last_transaction';
    public const TRANSACTION_LIST_KEY = 'transaction_lits';
    public const TRANSACTION_NAMESPACE = 'zap_transaction';
    public const TRANSACTION_POINTS_KEY = 'calculated_points';
    public const TRANSACTION_REFERENCE_KEY = 'reference_no';
    public const TRANSACTION_STATUS_CLEARED = 'cleared';
    public const TRANSACTION_STATUS_KEY = 'status';
    public const TRANSACTION_STATUS_VOIDED = 'voided';
    public const ZAP_REWARD_PECENTAGE = 0.02; // 2%
    public const MEMBER_BIRTHDAY_KEY = 'zap_member_birthday';
    public const MEMBER_GENDER_KEY = 'zap_member_gender';
    public const OTP_PURPOSE_MEMBERSHIP_UPDATE = 'API_UPDATE_MEMBERSHIP';
    public const OTP_PURPOSE_USE_POINTS = 'USE_POINTS';
    public const OTP_PURPOSE_GET_BALANCE = 'GET_BALANCE';
}
