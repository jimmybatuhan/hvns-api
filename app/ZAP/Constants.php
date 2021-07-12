<?php

namespace App\ZAP;

class Constants
{
    public const DISCOUNT_PREFIX = 'ZAP_POINTS';

    public const EMAIL_ALREADY_EXISTS = '409-03';
    public const MOBILE_ALREADY_EXISTS = '400-04';
    public const NOT_FOUND = '404-00';

    public const MEMBER_ID_KEY = 'zap_member_id';
    public const MEMBER_NAMESPACE = 'zap_member';
    public const MEMBER_POINTS_KEY = 'member_total_points';
    public const MEMBER_SINCE_KEY = 'member_since';

    public const VOID_POINT_STATUS = 'void';
    public const EARN_POINT_STATUS = 'earned';
    public const USE_POINT_STATUS = 'points_used';
    public const RETURNED_POINT_STATUS = 'points_returned';

    public const LAST_TRANSACTION_KEY = 'last_transaction';
    public const TRANSACTION_LIST_KEY = 'transaction_list';
    public const TRANSACTION_NAMESPACE = 'zap_transaction';
    public const TRANSACTION_POINTS_KEY = 'calculated_points';
    public const TRANSACTION_REFERENCE_KEY = 'reference_no';
    public const TRANSACTION_STATUS_CLEARED = 'cleared';
    public const TRANSACTION_STATUS_KEY = 'status';
    public const ZAP_REWARD_PECENTAGE = 0.02; // 2%
    public const MEMBER_BIRTHDAY_KEY = 'zap_member_birthday';
    public const MEMBER_GENDER_KEY = 'zap_member_gender';
    public const OTP_PURPOSE_MEMBERSHIP_UPDATE = 'API_UPDATE_MEMBERSHIP';
    public const OTP_PURPOSE_USE_POINTS = 'USE_POINTS';
    public const OTP_PURPOSE_GET_BALANCE = 'GET_BALANCE';
    public const POINTS_TO_EARN_KEY = 'points_to_earn';
    public const LINE_ITEM_POINTS = 'line_item_points';
    public const POINTS_EARNED = 'points_earned';
}
