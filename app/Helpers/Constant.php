<?php

// All status
const PAYMENT_STATUS_PENDING = 0;
const PAYMENT_STATUS_PAID = 1;
const PAYMENT_STATUS_CANCELLED = 2;

const STATUS_PENDING = 0;
const STATUS_SUCCESS = 1;
const STATUS_REJECT = 3;
const STATUS_ACTIVE = 1;
const STATUS_DRAFT = 2;
const STATUS_DISABLE = 3;
const STATUS_DEACTIVATE = 3;
const STATUS_EXPIRED = 4;
const STATUS_SUSPENDED = 5;
const STATUS_CANCELED = 2;
const STATUS_CANCELLED = 2;
const STATUS_REFUND = 6;

// User Role Type
const USER_STATUS_ACTIVE = 1;
const USER_STATUS_INACTIVE = 0;
const USER_ROLE_SUPER_ADMIN = 1;
const USER_ROLE_ADMIN = 2;
const USER_ROLE_USER = 3;
const USER_ROLE_STAFF = 4;


// Message
const SOMETHING_WENT_WRONG = "Something went wrong! Please try again";
const CREATED_SUCCESSFULLY = "Created Successfully";
const UPDATED_SUCCESSFULLY = "Updated Successfully";
const DELETED_SUCCESSFULLY = "Deleted Successfully";
const UPLOADED_SUCCESSFULLY = "Uploaded Successfully";
const DATA_FETCH_SUCCESSFULLY = "Data Fetch Successfully";
const SENT_SUCCESSFULLY = "Sent Successfully";
const DO_NOT_HAVE_PERMISSION = 7;
const CONFIG_NOT_FOUND = "Configuration not found";
const VALIDATION_FAILED = "Validation failed";
const CONFIG_VALIDATED = "Configuration is valid and ready to use";

// Currency placement
const CURRENCY_SYMBOL_BEFORE = 1;

// storage driver
const STORAGE_DRIVER_PUBLIC = 'public';
const STORAGE_DRIVER_AWS = 'aws';
const STORAGE_DRIVER_WASABI = 'wasabi';
const STORAGE_DRIVER_VULTR = 'vultr';
const STORAGE_DRIVER_DO = 'do';

const ACTIVE = 1;
const DEACTIVATE = 0;

const GATEWAY_MODE_LIVE = 1;
const GATEWAY_MODE_SANDBOX = 2;

//Gateway name
const PAYPAL = 'paypal';
const STRIPE = 'stripe';
const RAZORPAY = 'razorpay';
const INSTAMOJO = 'instamojo';
const MOLLIE = 'mollie';
const PAYSTACK = 'paystack';
const SSLCOMMERZ = 'sslcommerz';
const MERCADOPAGO = 'mercadopago';
const FLUTTERWAVE = 'flutterwave';
const BINANCE = 'binance';
const ALIPAY = 'alipay';
const BANK = 'bank';
const COINBASE = 'coinbase';
const PAYTM = 'paytm';
const MAXICASH = 'maxicash';
const IYZICO = 'iyzico';
const BITPAY = 'bitpay';
const ZITOPAY = 'zitopay';
const PAYHERE = 'payhere';
const CINETPAY = 'cinetpay';
const VOGUEPAY = 'voguepay';
const TOYYIBPAY = 'toyyibpay';
const PAYMOB = 'paymob';
const AUTHORIZE = 'authorize';
const XENDIT = 'xendit';
const PADDLE = 'paddle';


const DEFAULT_COLOR = 1;
const CUSTOM_COLOR = 2;

const LINK_SAAS_ADDON = "";
const RECURRING_GATEWAY = ['stripe', 'paypal'];

const SUBSCRIPTION_TYPE_MONTHLY = 1;
const SUBSCRIPTION_TYPE_YEARLY = 2;

const TRANSACTION_TYPE_SUBSCRIPTION = 1;

// HRM Constants
const EMPLOYMENT_TYPE_FULL_TIME = 1;
const EMPLOYMENT_TYPE_PART_TIME = 2;
const EMPLOYMENT_TYPE_CONTRACT = 3;
const EMPLOYMENT_TYPE_INTERN = 4;

const GENDER_MALE = 1;
const GENDER_FEMALE = 2;
const GENDER_OTHER = 3;

const EMPLOYEE_STATUS_ACTIVE = 1;
const EMPLOYEE_STATUS_ON_LEAVE = 2;
const EMPLOYEE_STATUS_TERMINATED = 3;

const ATTENDANCE_STATUS_PRESENT = 1;
const ATTENDANCE_STATUS_LATE = 2;
const ATTENDANCE_STATUS_ABSENT = 3;
const ATTENDANCE_STATUS_HALF_DAY = 4;
const ATTENDANCE_STATUS_ON_LEAVE = 5;

const LEAVE_TYPE_CASUAL = 1;
const LEAVE_TYPE_SICK = 2;
const LEAVE_TYPE_ANNUAL = 3;
const LEAVE_TYPE_MATERNITY = 4;
const LEAVE_TYPE_OTHER = 5;

const LEAVE_STATUS_PENDING = 0;
const LEAVE_STATUS_APPROVED = 1;
const LEAVE_STATUS_REJECTED = 2;

// Garments order status
const GARMENT_ORDER_STATUS_PENDING = 1;
const GARMENT_ORDER_STATUS_CONFIRMED = 2;
const GARMENT_ORDER_STATUS_IN_PRODUCTION = 3;
const GARMENT_ORDER_STATUS_COMPLETED = 4;
const GARMENT_ORDER_STATUS_CANCELLED = 5;

// Garments costing status
const GARMENT_COSTING_STATUS_DRAFT = 1;
const GARMENT_COSTING_STATUS_APPROVED = 2;

// Garments TNA status
const GARMENT_TNA_STATUS_PENDING = 1;
const GARMENT_TNA_STATUS_IN_PROGRESS = 2;
const GARMENT_TNA_STATUS_COMPLETED = 3;
const GARMENT_TNA_STATUS_DELAYED = 4;
const GARMENT_TNA_STATUS_CANCELLED = 5;

// Garments production plan status
const GARMENT_PLAN_STATUS_DRAFT = 1;
const GARMENT_PLAN_STATUS_PLANNED = 2;
const GARMENT_PLAN_STATUS_IN_PROGRESS = 3;
const GARMENT_PLAN_STATUS_COMPLETED = 4;
const GARMENT_PLAN_STATUS_CANCELLED = 5;

// Garments GRN status
const GARMENT_GRN_STATUS_RECEIVED = 1;
const GARMENT_GRN_STATUS_PARTIAL = 2;
const GARMENT_GRN_STATUS_REJECTED = 3;

// Garments store issue status
const GARMENT_ISSUE_STATUS_ISSUED = 1;
const GARMENT_ISSUE_STATUS_RETURNED = 2;
const GARMENT_ISSUE_STATUS_PARTIAL_RETURN = 3;

// Garments cutting status
const GARMENT_CUTTING_STATUS_PLANNED = 1;
const GARMENT_CUTTING_STATUS_IN_PROGRESS = 2;
const GARMENT_CUTTING_STATUS_COMPLETED = 3;

// Garments sewing production status
const GARMENT_SEWING_STATUS_RUNNING = 1;
const GARMENT_SEWING_STATUS_PAUSED = 2;
const GARMENT_SEWING_STATUS_COMPLETED = 3;

// Garments efficiency status
const GARMENT_EFFICIENCY_STATUS_RECORDED = 1;
const GARMENT_EFFICIENCY_STATUS_REVIEWED = 2;

// Garments quality statuses
const GARMENT_QC_STATUS_PENDING = 1;
const GARMENT_QC_STATUS_PASSED = 2;
const GARMENT_QC_STATUS_FAILED = 3;
const GARMENT_INSPECTION_RESULT_PENDING = 1;
const GARMENT_INSPECTION_RESULT_PASSED = 2;
const GARMENT_INSPECTION_RESULT_FAILED = 3;
const GARMENT_DEFECT_STATUS_OPEN = 1;
const GARMENT_DEFECT_STATUS_ACTION_TAKEN = 2;
const GARMENT_DEFECT_STATUS_CLOSED = 3;
const GARMENT_FINISHING_STATUS_PENDING = 1;
const GARMENT_FINISHING_STATUS_IN_PROGRESS = 2;
const GARMENT_FINISHING_STATUS_COMPLETED = 3;
const GARMENT_FINISHING_STATUS_HOLD = 4;
const GARMENT_PACKING_STATUS_DRAFT = 1;
const GARMENT_PACKING_STATUS_PACKED = 2;
const GARMENT_SHIPMENT_STATUS_DRAFT = 1;
const GARMENT_SHIPMENT_STATUS_READY = 2;
const GARMENT_SHIPMENT_STATUS_SHIPPED = 3;
const GARMENT_PROFIT_LOSS_STATUS_DRAFT = 1;
const GARMENT_PROFIT_LOSS_STATUS_FINAL = 2;
const GARMENT_ACCOUNTING_ENTRY_DRAFT = 1;
const GARMENT_ACCOUNTING_ENTRY_POSTED = 2;
const GARMENT_INCENTIVE_STATUS_DRAFT = 1;
const GARMENT_INCENTIVE_STATUS_APPROVED = 2;

// Ticket Status
const TICKET_STATUS_OPEN = 0;
const TICKET_STATUS_IN_PROGRESS = 1;
const TICKET_STATUS_RESOLVED = 2;
const TICKET_STATUS_CLOSED = 3;
const TICKET_STATUS_TRASHED = 4;

// Ticket Priority
const TICKET_PRIORITY_LOW = 0;
const TICKET_PRIORITY_MEDIUM = 1;
const TICKET_PRIORITY_HIGH = 2;

// Social Media Platforms
const SOCIAL_MEDIA_PLATFORMS = [
    1 => 'Facebook',
    2 => 'Twitter',
    3 => 'YouTube',
    4 => 'LinkedIn',
    5 => 'Instagram',
    6 => 'TikTok',
    7 => 'Threads'
];