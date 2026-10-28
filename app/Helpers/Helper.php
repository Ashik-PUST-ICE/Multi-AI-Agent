<?php

use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\FileManager;
use App\Models\Gateway;
use App\Models\Language;
use App\Models\Meta;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\SubscriptionEmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Mail\EmailNotify;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

if (!function_exists("getOption")) {
    function getOption($option_key, $default = NULL)
    {
        $system_settings = config('settings');

        if ($option_key && isset($system_settings[$option_key])) {
            return $system_settings[$option_key];
        } else {
            // Fallback for settings not in config (cache issues/provider not run)
            $setting = Setting::where('option_key', $option_key)->first();
            return $setting ? $setting->option_value : $default;
        }
    }
}

function getSettingImage($option_key)
{

    if ($option_key && $option_key != null) {
        $setting = Setting::where('option_key', $option_key)->first();
        if ($setting && filled($setting->option_value)) {

            $file = FileManager::select('path', 'storage_type')->find($setting->option_value);


            if (!is_null($file)) {
                if (Storage::disk($file->storage_type)->exists($file->path)) {

                    if ($file->storage_type == 'public') {
                        return asset('storage/' . $file->path);
                    }

                    return Storage::disk($file->storage_type)->url($file->path);
                }
            }
        }
    }
    return asset('assets/images/no-image.jpg');
}

if (!function_exists('getSettingImageOrDefault')) {
    function getSettingImageOrDefault($option_key, $defaultUrl)
    {
        $img = getSettingImage($option_key);
        $noImage = asset('assets/images/no-image.jpg');
        return ($img && $img !== $noImage) ? $img : $defaultUrl;
    }
}

function settingImageStoreUpdate($option_value, $requestFile)
{

    if ($requestFile) {

        /*File Manager Call upload*/
        if ($option_value && $option_value != null) {
            $new_file = FileManager::where('id', $option_value)->first();

            if ($new_file) {
                $new_file->removeFile();
                $uploaded = $new_file->upload('Setting', $requestFile, '', $new_file->id);
            } else {
                $new_file = new FileManager();
                $uploaded = $new_file->upload('Setting', $requestFile);
            }
        } else {
            $new_file = new FileManager();
            $uploaded = $new_file->upload('Setting', $requestFile);
        }

        /*End*/

        return $uploaded->id;
    }

    return null;
}


if (!function_exists("getDefaultImage")) {
    function getDefaultImage()
    {
        // return asset('assets/images/no-image.jpg');
        return asset('assets/images/icon/upload-img-1.svg');
    }
}


if (!function_exists("toastMessage")) {
    function toastMessage($message_type, $message)
    {
        Toastr::$message_type($message, '', ['progressBar' => true, 'closeButton' => true, 'positionClass' => 'toast-top-right']);
    }
}

if (!function_exists("getDefaultLanguage")) {
    function getDefaultLanguage()
    {
        $language = Language::where('default', STATUS_ACTIVE)->first();
        if ($language) {
            $iso_code = $language->iso_code;
            return $iso_code;
        }

        return 'en';
    }
}

if (!function_exists("getCurrencySymbol")) {
    function getCurrencySymbol()
    {
        $currency = Currency::where('current_currency', STATUS_ACTIVE)->first();
        if ($currency) {
            $symbol = $currency->symbol;
            return $symbol;
        }

        return '';
    }
}

if (!function_exists("getIsoCode")) {
    function getIsoCode()
    {
        $currency = Currency::where('current_currency', STATUS_ACTIVE)->first();
        if ($currency) {
            $currency_code = $currency->currency_code;
            return $currency_code;
        }

        return '';
    }
}

if (!function_exists("getCurrencyPlacement")) {
    function getCurrencyPlacement()
    {
        $currency = Currency::where('current_currency', STATUS_ACTIVE)->first();
        $placement = 'before';
        if ($currency) {
            $placement = $currency->currency_placement;
            return $placement;
        }

        return $placement;
    }
}

if (!function_exists("showPrice")) {
    function showPrice($price)
    {
        $price = getNumberFormat($price);
        if (config('app.currencyPlacement') == 'after') {
            return $price . config('app.currencySymbol');
        } else {
            return config('app.currencySymbol') . $price;
        }
    }
}


if (!function_exists("getNumberFormat")) {
    function getNumberFormat($amount)
    {
        return number_format($amount, 2, '.', '');
    }
}

if (!function_exists("decimalToInt")) {
    function decimalToInt($amount)
    {
        return number_format(number_format($amount, 2, '.', '') * 100, 0, '.', '');
    }
}

if (!function_exists("intToDecimal")) {
}
function intToDecimal($amount)
{
    return number_format($amount / 100, 2, '.', '');
}

if (!function_exists("appLanguages")) {
    function appLanguages()
    {
        return Language::where('status', 1)->get();
    }
}

if (!function_exists("selectedLanguage")) {
    function selectedLanguage()
    {

        $language = Language::where('iso_code', session()->get('local'))->first();

        if (!$language) {
            $language = Language::first();
            if ($language) {
                $ln = $language->iso_code;
                session(['local' => $ln]);
                App::setLocale(session()->get('local'));
            }
        }

        return $language;
    }
}

if (!function_exists("getVideoFile")) {
    function getFile($path, $storageType)
    {
        if (!is_null($path)) {
            if (Storage::disk($storageType)->exists($path)) {

                if ($storageType == 'public') {
                    return asset('storage/' . $path);
                }

                if ($storageType == 'wasabi') {
                    return Storage::disk('wasabi')->url($path);
                }


                return Storage::disk($storageType)->url($path);
            }
        }

        return asset('assets/images/no-image.jpg');
    }
}

if (!function_exists("notificationForUser")) {
    function notificationForUser()
    {
        $instructor_notifications = \App\Models\Notification::where('user_id', auth()->user()->id)->where('user_type', 2)->where('is_seen', 'no')->orderBy('created_at', 'DESC')->get();
        $student_notifications = \App\Models\Notification::where('user_id', auth()->user()->id)->where('user_type', 3)->where('is_seen', 'no')->orderBy('created_at', 'DESC')->get();
        return array('instructor_notifications' => $instructor_notifications, 'student_notifications' => $student_notifications);
    }
}

if (!function_exists("adminNotifications")) {
    function adminNotifications()
    {
        return \App\Models\Notification::where('user_type', 1)->where('is_seen', 'no')->orderBy('created_at', 'DESC')->paginate(5);
    }
}

if (!function_exists('getSlug')) {
    function getSlug($text)
    {
        if ($text) {
            $text = preg_replace("/[\n\t]/", " ", $text);
            $data = preg_replace("/[~`{}.'\"\!\@\#\$\%\^\&\*\(\)\_\=\+\/\?\>\<\,\[\]\:\;\|\\\]/", "", $text);
            $slug = preg_replace("/[\/_|+ -]+/", "-", $data);
            return $slug;
        }
        return '';
    }
}


if (!function_exists('getCustomerCurrentBuildVersion')) {
    function getCustomerCurrentBuildVersion()
    {
        $buildVersion = getOption('build_version');

        if (is_null($buildVersion)) {
            return 1;
        }

        return (int)$buildVersion;
    }
}

if (!function_exists('setCustomerBuildVersion')) {
    function setCustomerBuildVersion($version)
    {
        $option = Setting::firstOrCreate(['option_key' => 'build_version']);
        $option->option_value = $version;
        $option->save();
    }
}

if (!function_exists('setCustomerCurrentVersion')) {
    function setCustomerCurrentVersion()
    {
        $option = Setting::firstOrCreate(['option_key' => 'current_version']);
        $option->option_value = config('app.current_version');
        $option->save();
    }
}


if (!function_exists('updateEnv')) {
    function updateEnv($values)
    {
        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {
                setEnvironmentValue($envKey, $envValue);
            }
            return true;
        }
    }
}

if (!function_exists('setEnvironmentValue')) {
    function setEnvironmentValue($envKey, $envValue)
    {
        try {
            $envFile = app()->environmentFilePath();
            $str = file_get_contents($envFile);
            $str .= "\n"; // In case the searched variable is in the last line without \n
            $keyPosition = strpos($str, "{$envKey}=");
            if ($keyPosition) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $endOfLinePosition = strpos($str, "\n", $keyPosition);
                } else {
                    $endOfLinePosition = strpos($str, PHP_EOL, $keyPosition);
                }
                $oldLine = substr($str, $keyPosition, $endOfLinePosition - $keyPosition);
                $envValue = str_replace(chr(92), "\\\\", $envValue);
                $envValue = str_replace('"', '\"', $envValue);
                $newLine = "{$envKey}=\"{$envValue}\"";
                if ($oldLine != $newLine) {
                    $str = str_replace($oldLine, $newLine, $str);
                    $str = substr($str, 0, -1);
                    $fp = fopen($envFile, 'w');
                    fwrite($fp, $str);
                    fclose($fp);
                }
            } else if (strtoupper($envKey) == $envKey) {
                $envValue = str_replace(chr(92), "\\\\", $envValue);
                $envValue = str_replace('"', '\"', $envValue);
                $newLine = "{$envKey}=\"{$envValue}\"\n";
                $str .= $newLine;
                $str = substr($str, 0, -1);
                $fp = fopen($envFile, 'w');
                fwrite($fp, $str);
                fclose($fp);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('base64urlEncode')) {
    function base64urlEncode($str)
    {
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    }
}

if (!function_exists('getTimeZone')) {
    function getTimeZone()
    {
        return DateTimeZone::listIdentifiers(
            DateTimeZone::ALL
        );
    }
}

if (!function_exists('formatDateToCustomer')) {
    function formatDateToCustomer($date, $format, $sourceTimezone, $newTimezone)
    {
        $carbonDate = Carbon::parse($date, $sourceTimezone);
        return $carbonDate->timezone($newTimezone)->format($format);
    }
}

if (!function_exists('getAppTimeZone')) {
    function getAppTimeZone()
    {
        return getOption('app_timezone', config('app.timezone', 'UTC'));
    }
}

if (!function_exists('getErrorMessage')) {
    function getErrorMessage($e, $customMsg = null)
    {
        if ($customMsg != null) {
            return $customMsg;
        }
        if (env('APP_DEBUG')) {
            return $e->getMessage() . $e->getLine();
        } else {
            return SOMETHING_WENT_WRONG;
        }
    }
}

if (!function_exists('getUserData')) {
    function getUserData($user_id, $property)
    {
        $data = User::where('id', $user_id)->first();
        if (!is_null($data) && isset($data->{$property})) {
            return $data->{$property};
        }
        return $property === 'image' ? asset('assets/images/no-image.jpg') : null;
    }
}

if (!function_exists('getTicketIdHtml')) {
    function getTicketIdHtml($data, $routePrefix = 'admin')
    {
        $routeName = $routePrefix === 'super_admin' ? 'super_admin.ticket' : 'admin.ticket';
        $detailUrl = route($routeName . '.details', encrypt($data->id));
        $ticketId = $data->ticket_id ?? 'ST' . str_pad($data->id, 6, '0', STR_PAD_LEFT);
        if (isset($data->last_reply_id) && $data->last_reply_id == null && $data->status == TICKET_STATUS_OPEN) {
            return '<a href="' . $detailUrl . '" class="ticket-tracking-id">' . $ticketId . ' <span class="badge bg-danger position-absolute rounded-pill start-100 top-0 translate-middle">' . __('New') . '</span></a>';
        }
        if (isset($data->is_seen) && $data->is_seen == 0) {
            return '<a href="' . $detailUrl . '" class="ticket-tracking-id">' . $ticketId . ' <span class="badge bg-danger position-absolute rounded-pill start-100 top-0 translate-middle"><i class="fa-regular fa-envelope mb-0"></i></span></a>';
        }
        return '<a href="' . $detailUrl . '" class="ticket-tracking-id">' . $ticketId . '</a>';
    }
}

if (!function_exists("fileUpload")) {
    function fileUpload($to, $file, $name = NULL)
    {

        try {
            $extension = $file->getClientOriginalExtension();
            if ($name == '') {
                $file_name = rand(000, 999) . time() . '.' . $extension;
            } else {
                $file_name = $name . '-' . time() . '.' . $extension;
            }
            $file_name = 'uploads/' . $to . '/' . str_replace(' ', '_', $file_name);

            Storage::disk(config('app.STORAGE_DRIVER'))
                ->put($file_name, file_get_contents($file->getRealPath()));

            return $file_name;
        } catch (\Exception $e) {
            return NULL;
        }
    }
}



if (!function_exists('getFileUrl')) {
    function getFileUrl($id = null): string
    {

        $file = FileManager::select('path', 'storage_type')->find($id);

        if (!is_null($file)) {
            if (Storage::disk($file->storage_type)->exists($file->path)) {

                if ($file->storage_type == 'public') {
                    return asset('storage/' . $file->path);
                }

                if ($file->storage_type == 'wasabi') {
                    return Storage::disk('wasabi')->url($file->path);
                }


                return Storage::disk($file->storage_type)->url($file->path);
            }
        }

        return asset('assets/images/no-image.jpg');
    }
}

if (!function_exists('getFileData')) {
    function getFileData($id, $property)
    {
        $file = FileManager::find($id);
        if ($file) {
            return $file->{$property};
        }
        return null;
    }
}

if (!function_exists('languageLocale')) {
    function languageLocale($locale)
    {
        $data = Language::where('code', $locale)->first();
        if ($data) {
            return $data->code;
        }
        return 'en';
    }
}


if (!function_exists('getUseCase')) {
    function getUseCase($useCase = [])
    {
        if (in_array("-1", $useCase)) {
            return __("All");
        }
        return count($useCase);
    }
}

function currentCurrency($attribute = '')
{
    $currentCurrency = Currency::where('current_currency', 1)->first();
    if (isset($currentCurrency->{$attribute})) {
        return $currentCurrency->{$attribute};
    }
    return '';
}

function currentCurrencyType()
{
    $currentCurrency = Currency::where('current_currency', 1)->first();
    return $currentCurrency?->currency_code;
}

function currentCurrencyIcon()
{
    $currentCurrency = Currency::where('current_currency', 1)->first();
    return $currentCurrency->symbol;
}

function convertCurrencySwap($amount, $to = 'USD', $from = 'USD')
{
    try {
        $jsondata = "";

        $coinPriceInCurrency = Setting::where('option_key', 'COIN_PRICE_IN_CURRENCY_FOR' . $from)->first();
        if ($coinPriceInCurrency != null) {

            if ($coinPriceInCurrency->option_value == null) {
                $url = "https://min-api.cryptocompare.com/data/price?fsym=$from&tsyms=$to";
                $json = file_get_contents($url); //,FALSE,$ctx);
                $jsondata =  json_decode($json, TRUE);

                $coinPriceInCurrency->option_value = $jsondata[$to];
                $coinPriceInCurrency->save();
            }

            $dateTime = Carbon::now()->addMinute(5);
            $currentTime = $dateTime->format('Y-m-d H:i:s');

            if (($coinPriceInCurrency->option_value != null) && (date('Y-m-d H:i:s', strtotime($coinPriceInCurrency->updated_at)) < $currentTime)) {
                $url = "https://min-api.cryptocompare.com/data/price?fsym=$from&tsyms=$to";
                $json = file_get_contents($url); //,FALSE,$ctx);
                $jsondata =  json_decode($json, TRUE);

                $coinPriceInCurrency->option_value = $jsondata[$to];
                $coinPriceInCurrency->save();
            }
        } else {

            $url = "https://min-api.cryptocompare.com/data/price?fsym=$from&tsyms=$to";
            $json = file_get_contents($url); //,FALSE,$ctx);
            $jsondata =  json_decode($json, TRUE);

            if ($jsondata != null) {
                $newObj = new Setting();
                $newObj->option_key = 'COIN_PRICE_IN_CURRENCY_FOR' . $from;
                $newObj->option_value = $jsondata[$to];
                $newObj->save();
            }
        }

        return [
            'total' => $amount * getOption('COIN_PRICE_IN_CURRENCY_FOR' . $from),
            'price' => getOption('COIN_PRICE_IN_CURRENCY_FOR' . $from)
        ];
    } catch (\Exception $e) {
        return [
            'total' => 0.00000000,
            'price' => 0.00000000
        ];
    }
}

function random_strings($length_of_string)
{
    $str_result = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890abcdefghijklmnopqrstuvwxyz';
    return substr(str_shuffle($str_result), 0, $length_of_string);
}

function broadcastPrivate($eventName, $broadcastData, $userId)
{
    //    $channelName = 'private-'.env("PUSHER_PRIVATE_CHANEL_NAME").'.' . customEncrypt($userId);
    //    dispatch(new BroadcastJob($channelName, $eventName, $broadcastData))->onQueue('broadcast-data');
}

function getUserId()
{
    try {
        return Auth::id();
    } catch (\Exception $e) {
        return 0;
    }
}


if (!function_exists('visual_number_format')) {
    function visual_number_format($value)
    {
        if (is_integer($value)) {
            return number_format($value, 2, '.', '');
        } elseif (is_string($value)) {
            $value = floatval($value);
        }
        $number = explode('.', number_format($value, 10, '.', ''));
        $intVal = (int)$value;
        if ($value > $intVal || $value < 0) {
            $intPart = $number[0];
            $floatPart = substr($number[1], 0, 8);
            $floatPart = rtrim($floatPart, '0');
            if (strlen($floatPart) < 2) {
                $floatPart = substr($number[1], 0, 2);
            }
            return $intPart . '.' . $floatPart;
        }
        return $number[0] . '.' . substr($number[1], 0, 2);
    }
}

function getError($e)
{
    if (env('APP_DEBUG')) {
        return " => " . $e->getMessage();
    }
    return '';
}

function notification($title = null, $body = null, $user_id = null, $link = null)
{
    try {
        $obj = new Notification();
        $obj->title = $title;
        $obj->body = $body;
        $obj->user_id = $user_id;
        $obj->link = $link;
        $obj->save();
        return "notification sent!";
    } catch (\Exception $e) {
        return "something error!";
    }
}

if (!function_exists('get_default_language')) {
    function get_default_language()
    {
        $language = Language::where('default', STATUS_ACTIVE)->first();
        if ($language) {
            $iso_code = $language->iso_code;
            return $iso_code;
        }

        return 'en';
    }
}

if (!function_exists('get_currency_symbol')) {
    function get_currency_symbol()
    {
        $currency = Currency::where('current_currency', STATUS_ACTIVE)->first();
        if ($currency) {
            $symbol = $currency->symbol;
            return $symbol;
        }

        return '';
    }
}

if (!function_exists('get_currency_code')) {
    function get_currency_code()
    {
        $currency = Currency::where('current_currency', STATUS_ACTIVE)->first();
        if ($currency) {
            $currency_code = $currency->currency_code;
            return $currency_code;
        }

        return '';
    }
}

if (!function_exists('get_currency_placement')) {
    function get_currency_placement()
    {
        $currency = Currency::where('current_currency', STATUS_ACTIVE)->first();
        $placement = 'before';
        if ($currency) {
            $placement = $currency->currency_placement;
            return $placement;
        }

        return $placement;
    }
}

if (!function_exists('customNumberFormat')) {
    function customNumberFormat($value)
    {
        $number = explode('.', $value);
        if (!isset($number[1])) {
            return number_format($value, 8, '.', '');
        } else {
            $result = substr($number[1], 0, 8);
            if (strlen($result) < 8) {
                $result = number_format($value, 8, '.', '');
            } else {
                $result = $number[0] . "." . $result;
            }

            return $result;
        }
    }
}

function humanFileSize($size, $unit = '')
{
    if ((!$unit && $size >= 1 << 30) || $unit == 'GB') {
        return number_format($size / (1 << 30), 2) . 'GB';
    }

    if ((!$unit && $size >= 1 << 20) || $unit == 'MB') {
        return number_format($size / (1 << 20), 2) . 'MB';
    }

    if ((!$unit && $size >= 1 << 10) || $unit == 'KB') {
        return number_format($size / (1 << 10), 2) . 'KB';
    }

    return number_format($size) . ' bytes';
}

if (!function_exists('getMeta')) {
    function getMeta($slug)
    {
        $metaData = [
            'meta_title' => null,
            'meta_description' => null,
            'meta_keyword' => null,
            'og_image' => null,
        ];

        if (class_exists(\App\Models\Meta::class)) {
            $meta = \App\Models\Meta::where('slug', $slug)->select([
                'meta_title',
                'meta_description',
                'meta_keyword',
                'og_image',
            ])->first();

            if (!is_null($meta)) {
                $metaData = $meta->toArray();
            } else {
                $meta = \App\Models\Meta::where('slug', 'default')->select([
                    'meta_title',
                    'meta_description',
                    'meta_keyword',
                    'og_image',
                ])->first();

                if (!is_null($meta)) {
                    $metaData = $meta->toArray();
                }
            }
        }

        $metaData['meta_title'] = $metaData['meta_title'] != NULL ? $metaData['meta_title'] : getOption('app_name');
        $metaData['meta_description'] = $metaData['meta_description'] != NULL ? $metaData['meta_description'] : getOption('app_name');
        $metaData['meta_keyword'] = $metaData['meta_keyword'] != NULL ? $metaData['meta_keyword'] : getOption('app_name');
        $metaData['og_image'] = $metaData['og_image'] != NULL ? getFileUrl($metaData['og_image']) : getFileUrl(getOption('app_logo'));

        return $metaData;
    }
}

if (!function_exists('genericEmailNotify')) {
    function genericEmailNotify($singleData = NULL, $userData = NULL, $customData = NULL, $template = NULL, $link = NULL)
    {
        if (getOption('app_mail_status') == STATUS_ACTIVE) {
            try {
                if ($singleData != NULL && $singleData != "") {
                    Mail::to($singleData->to)->send(new EmailNotify($singleData, $userData, $customData, $template, $link));
                }
            } catch (\Exception $e) {
                //                return "something error!";
            }
        }
    }
}

if (!function_exists('getPostTypes')) {
    function getPostTypes()
    {
        return [
            'Video' => __('Video'),
            'Image' => __('Image'),
            'Audio' => __('Audio'),
            'Text' => __('Text'),
            'Mixed' => __('Mixed'),
        ];
    }
}

if (!function_exists('moduleName')) {
    function moduleName($module)
    {
        return ucwords(str_replace(['-', '_', '.'], ' ', $module));
    }
}

if (!function_exists('adminCan')) {
    function adminCan(string $permission): bool
    {
        if (!auth()->check()) return false;
        if (auth()->user()->role == USER_ROLE_SUPER_ADMIN) return true;
        return auth()->user()->can($permission);
    }
}

if (!function_exists('setCommonNotification')) {
    function setCommonNotification($title, $body, $link = null)
    {
        try {
            $obj = new \App\Models\Notification();
            $obj->title = $title;
            $obj->body = $body;
            $obj->user_id = auth()->id();
            $obj->link = $link;
            $obj->save();
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}

// ============================================================
// Subscription Plan Limit Helper Functions
// ============================================================

if (!function_exists('getUserCurrentPackage')) {
    /**
     * Get the current active package (with packageable) for a user.
     * Returns the UserPackage model with loaded Package relation, or null.
     */
    function getUserCurrentPackage($userId = null)
    {
        $userId = $userId ?? auth()->id();
        if (!$userId) return null;

        return \App\Models\UserPackage::query()
            ->with('packageable')
            ->where('user_id', $userId)
            ->where('status', STATUS_ACTIVE)
            ->whereDate('end_date', '>=', now())
            ->first();
    }
}

if (!function_exists('getUserPostedCount')) {
    /**
     * Count the number of successfully published posts for a user
     * within their current billing period (start_date to end_date of active plan).
     */
    function getUserPostedCount($userId = null)
    {
        $userId = $userId ?? auth()->id();
        $userPackage = getUserCurrentPackage($userId);

        if (!$userPackage) {
            return 0;
        }

        if (!class_exists(\App\Models\ScheduledPost::class)) {
            return 0;
        }

        return \App\Models\ScheduledPost::where('user_id', $userId)
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$userPackage->start_date, $userPackage->end_date])
            ->count();
    }
}

if (!function_exists('hasReachedPostLimit')) {
    /**
     * Check if the user has reached their plan's post limit.
     * Returns true if limit is reached (cannot publish more).
     * Post limits are no longer enforced by package plans.
     */
    function hasReachedPostLimit($userId = null)
    {
        return false;

        // No active plan → treat as limit reached
        if (is_null($limit)) {
            return true;
        }

        // 0 means unlimited
        if ($limit === 0) {
            return false;
        }

        $postedCount = getUserPostedCount($userId);
        return $postedCount >= $limit;
    }
}

if (!function_exists('isProviderAllowedByPlan')) {
    /**
     * Check if a given platform (string like 'Facebook', 'Twitter', etc.)
     * is allowed by the user's current subscription plan.
     *
     * Provider access is no longer restricted by package.
     */
    function isProviderAllowedByPlan($platformName, $userId = null)
    {
        foreach (SOCIAL_MEDIA_PLATFORMS as $key => $name) {
            if (strtolower($name) === strtolower($platformName)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('getProviderLimitCheckResult')) {
    /**
     * Get a structured result for provider limit check.
     * Returns ['allowed' => bool, 'message' => string]
     */
    function getProviderLimitCheckResult($platformName, $userId = null)
    {
        if (isProviderAllowedByPlan($platformName, $userId)) {
            return ['allowed' => true, 'message' => ''];
        }

        return [
            'allowed' => false,
            'message' => __('Your current plan does not include :platform. Please upgrade your package.', [
                'platform' => ucfirst($platformName)
            ])
        ];
    }
}

if (!function_exists('getPostLimitCheckResult')) {
    /**
     * Get a structured result for post limit check.
     * Returns ['allowed' => bool, 'message' => string, 'limit' => int|null, 'used' => int]
     */
    function getPostLimitCheckResult($userId = null)
    {
        return [
            'allowed' => true,
            'message' => '',
            'limit' => 0,
            'used' => getUserPostedCount($userId),
        ];
    }
}

if (!function_exists('getUserAllowedProviders')) {
    /**
     * Get the list of allowed provider names for the current user's plan.
     * Returns an array of lowercase platform names, e.g. ['facebook', 'twitter'].
     * Returns empty array if no plan or no providers allowed.
     */
    function getUserAllowedProviders($userId = null)
    {
        return array_map('strtolower', array_values(SOCIAL_MEDIA_PLATFORMS));
    }
}
