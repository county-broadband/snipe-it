<?php
namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Http\Requests\AssetRequest;
use App\Http\Requests\AssetFileRequest;
use App\Http\Requests\AssetCheckinRequest;
use App\Http\Requests\AssetCheckoutRequest;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\AssetModel;
use App\Models\AssetModels;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Depreciation;
use App\Models\Location;
use App\Models\Manufacturer; //for embedded-create
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Validator;
use Artisan;
use Auth;
use Config;
use DB;
use Image;
use Input;
use Lang;
use Log;
use Mail;
use Paginator;
use Redirect;
use Response;
use Slack;
use Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use TCPDF;
use View;

/**
 * This class controls all actions related to assets for
 * the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 * @author [A. Gianotto] [<snipe@snipe.net>]
 */
class AssetsController extends Controller
{
    protected $qrCodeDimensions = array( 'height' => 3.5, 'width' => 3.5);
    protected $barCodeDimensions = array( 'height' => 2, 'width' => 22);


    public function __construct()
    {
        $this->middleware('auth');
        parent::__construct();
    }


    /**
    * Returns a view that invokes the ajax tables which actually contains
    * the content for the assets listing, which is generated in getDatatable.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @see AssetController::getDatatable() method that generates the JSON response
    * @since [v1.0]
    * @return View
    */
    public function getIndex()
    {
        return View::make('hardware/index');
    }

    /**
    * Returns a view that presents a form to create a new asset.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @since [v1.0]
    * @return View
    */
    public function getCreate($model_id = null)
    {
        // Grab the dropdown lists
        $model_list = Helper::modelList();
        $statuslabel_list = Helper::statusLabelList();
        $location_list = Helper::locationsList();
        $manufacturer_list = Helper::manufacturerList();
        $category_list = Helper::categoryList();
        $supplier_list = Helper::suppliersList();
        $company_list = Helper::companyList();
        $assigned_to = Helper::usersList();
        $statuslabel_types = Helper::statusTypeList();

        $view = View::make('hardware/edit');
        $view->with('supplier_list', $supplier_list);
        $view->with('company_list', $company_list);
        $view->with('model_list', $model_list);
        $view->with('statuslabel_list', $statuslabel_list);
        $view->with('assigned_to', $assigned_to);
        $view->with('location_list', $location_list);
        $view->with('asset', new Asset);
        $view->with('manufacturer', $manufacturer_list);
        $view->with('category', $category_list);
        $view->with('statuslabel_types', $statuslabel_types);

        if (!is_null($model_id)) {
            $selected_model = AssetAssetModel::find($model_id);
            $view->with('selected_model', $selected_model);
        }

        return $view;
    }

    /**
    * Validate and process new asset form data.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @since [v1.0]
    * @return Redirect
    */
    public function postCreate(AssetRequest $request)
    {
        // create a new model instance
        $asset = new Asset();
        $asset->model()->associate(AssetModel::find(e(Input::get('model_id'))));

        $checkModel = config('app.url').'/api/models/'.e(Input::get('model_id')).'/check';

        $asset->name                    = e(Input::get('name'));
        $asset->serial                  = e(Input::get('serial'));
        $asset->company_id              = Company::getIdForCurrentUser(e(Input::get('company_id')));
        $asset->model_id                = e(Input::get('model_id'));
        $asset->order_number            = e(Input::get('order_number'));
        $asset->notes                   = e(Input::get('notes'));
        $asset->asset_tag               = e(Input::get('asset_tag'));
        $asset->user_id                 = Auth::user()->id;
        $asset->archived                    = '0';
        $asset->physical                    = '1';
        $asset->depreciate                  = '0';
        if (e(Input::get('status_id')) == '') {
            $asset->status_id =  null;
        } else {
            $asset->status_id = e(Input::get('status_id'));
        }

        if (e(Input::get('warranty_months')) == '') {
            $asset->warranty_months =  null;
        } else {
            $asset->warranty_months        = e(Input::get('warranty_months'));
        }

        if (e(Input::get('purchase_cost')) == '') {
            $asset->purchase_cost =  null;
        } else {
            $asset->purchase_cost = \App\Helpers\Helper::ParseFloat(e(Input::get('purchase_cost')));
        }

        if (e(Input::get('purchase_date')) == '') {
            $asset->purchase_date =  null;
        } else {
            $asset->purchase_date        = e(Input::get('purchase_date'));
        }

        if (e(Input::get('assigned_to')) == '') {
            $asset->assigned_to =  null;
        } else {
            $asset->assigned_to        = e(Input::get('assigned_to'));
        }

        if (e(Input::get('supplier_id')) == '') {
            $asset->supplier_id =  0;
        } else {
            $asset->supplier_id        = e(Input::get('supplier_id'));
        }

        if (e(Input::get('requestable')) == '') {
            $asset->requestable =  0;
        } else {
            $asset->requestable        = e(Input::get('requestable'));
        }

        if (e(Input::get('rtd_location_id')) == '') {
            $asset->rtd_location_id = null;
        } else {
            $asset->rtd_location_id     = e(Input::get('rtd_location_id'));
        }

        // Create the image (if one was chosen.)
        if (Input::file('image')) {
            $image = Input::file('image');
            $file_name = str_random(25).".".$image->getClientOriginalExtension();
            $path = public_path('uploads/assets/'.$file_name);
            Image::make($image->getRealPath())->resize(300, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->save($path);
                $asset->image = $file_name;

        }

            // Was the asset created?
        if ($asset->save()) {

            if (Input::get('assigned_to')!='') {
                    $logaction = new Actionlog();
                    $logaction->asset_id = $asset->id;
                    $logaction->checkedout_to = $asset->assigned_to;
                    $logaction->asset_type = 'hardware';
                    $logaction->user_id = Auth::user()->id;
                    $logaction->note = e(Input::get('note'));
                    $log = $logaction->logaction('checkout');
            }
            // Redirect to the asset listing page
            return Redirect::to("hardware")->with('success', trans('admin/hardware/message.create.success'));
        }

        return Redirect::back()->withInput()->withErrors($asset->getErrors());

    }

    /**
    * Returns a view that presents a form to edit an existing asset.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return View
    */
    public function getEdit($assetId = null)
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find($assetId))) {
            // Redirect to the asset management page
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        // Grab the dropdown lists
        $model_list = Helper::modelList();
        $statuslabel_list = Helper::statusLabelList();
        $location_list = Helper::locationsList();
        $manufacturer_list = Helper::manufacturerList();
        $category_list = Helper::categoryList();
        $supplier_list = Helper::suppliersList();
        $company_list = Helper::companyList();
        $assigned_to = Helper::usersList();
        $statuslabel_types =Helper:: statusTypeList();

        return View::make('hardware/edit', compact('asset'))
        ->with('model_list', $model_list)
        ->with('supplier_list', $supplier_list)
        ->with('company_list', $company_list)
        ->with('location_list', $location_list)
        ->with('statuslabel_list', $statuslabel_list)
        ->with('assigned_to', $assigned_to)
        ->with('manufacturer', $manufacturer_list)
        ->with('statuslabel_types', $statuslabel_types)
        ->with('category', $category_list);
    }


    /**
    * Validate and process asset edit form.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return Redirect
    */
    public function postEdit($assetId = null)
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find($assetId))) {
            // Redirect to the asset management page with error
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        // $input=Input::all();
        // // return "INPUT IS: <pre>".print_r($input,true)."</pre>";
        // $rules=$asset->validationRules($assetId);
        // $model=AssetModel::find(e(Input::get('model_id'))); //validate by the NEW model's custom fields, not the current one
        // if($model->fieldset)
        // {
        //   foreach($model->fieldset->fields AS $field) {
        //     $input[$field->db_column_name()]=$input['fields'][$field->db_column_name()];
        //     $asset->{$field->db_column_name()}=$input[$field->db_column_name()];
        //   }
        //   $rules+=$model->fieldset->validation_rules();
        //   unset($input['fields']);
        // }

        //return "Rules: <pre>".print_r($rules,true)."</pre>";

        //attempt to validate
        // $validator = Validator::make($input,  $rules );
        //
        // $custom_errors=[];


        if (e(Input::get('status_id')) == '') {
            $asset->status_id =  null;
        } else {
            $asset->status_id = e(Input::get('status_id'));
        }

        if (e(Input::get('warranty_months')) == '') {
            $asset->warranty_months =  null;
        } else {
            $asset->warranty_months = e(Input::get('warranty_months'));
        }

        if (e(Input::get('purchase_cost')) == '') {
            $asset->purchase_cost =  null;
        } else {
            $asset->purchase_cost = \App\Helpers\Helper::ParseFloat(e(Input::get('purchase_cost')));
        }

        if (e(Input::get('purchase_date')) == '') {
            $asset->purchase_date =  null;
        } else {
            $asset->purchase_date        = e(Input::get('purchase_date'));
        }

        if (e(Input::get('supplier_id')) == '') {
            $asset->supplier_id =  null;
        } else {
            $asset->supplier_id        = e(Input::get('supplier_id'));
        }

        if (e(Input::get('requestable')) == '') {
            $asset->requestable =  0;
        } else {
            $asset->requestable        = e(Input::get('requestable'));
        }

        if (e(Input::get('rtd_location_id')) == '') {
            $asset->rtd_location_id = 0;
        } else {
            $asset->rtd_location_id     = e(Input::get('rtd_location_id'));
        }

        if (Input::has('image_delete')) {
            unlink(public_path().'/uploads/assets/'.$asset->image);
            $asset->image = '';
        }



        $checkModel = config('app.url').'/api/models/'.e(Input::get('model_id')).'/check';

        // Update the asset data
        $asset->name         = e(Input::get('name'));
        $asset->serial       = e(Input::get('serial'));
        $asset->company_id   = Company::getIdForCurrentUser(e(Input::get('company_id')));
        $asset->model_id     = e(Input::get('model_id'));
        $asset->order_number = e(Input::get('order_number'));
        $asset->asset_tag    = e(Input::get('asset_tag'));
        $asset->notes        = e(Input::get('notes'));
        $asset->physical     = '1';

           // Update the image
        if (Input::file('image')) {
            $image = Input::file('image');
            $file_name = str_random(25).".".$image->getClientOriginalExtension();
            $path = public_path('uploads/assets/'.$file_name);
            Image::make($image->getRealPath())->resize(300, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->save($path);
            $asset->image = $file_name;
        }



        // Was the asset updated?
        if ($asset->save()) {
            // Redirect to the new asset page
            return Redirect::to("hardware/$assetId/view")->with('success', trans('admin/hardware/message.update.success'));
        }

        return Redirect::back()->withInput()->withErrors($asset->getErrors());

    }

    /**
    * Delete a given asset (mark as deleted).
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return Redirect
    */
    public function getDelete($assetId)
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find($assetId))) {
            // Redirect to the asset management page with error
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        DB::table('assets')
        ->where('id', $asset->id)
        ->update(array('assigned_to' => null));


        $asset->delete();

        // Redirect to the asset management page
        return Redirect::to('hardware')->with('success', trans('admin/hardware/message.delete.success'));




    }

    /**
    * Returns a view that presents a form to check an asset out to a
    * user.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return View
    */
    public function getCheckout($assetId)
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find(e($assetId)))) {
            // Redirect to the asset management page with error
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        // Get the dropdown of users and then pass it to the checkout view
        $users_list = Helper::usersList();

        return View::make('hardware/checkout', compact('asset'))->with('users_list', $users_list);

    }

    /**
    * Validate and process the form data to check out an asset to a user.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return Redirect
    */
    public function postCheckout(AssetCheckoutRequest $request, $assetId)
    {

        // Check if the asset exists
        if (!$asset = Asset::find($assetId)) {
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        $user = User::find(e(Input::get('assigned_to')));
        $admin = Auth::user();



        if (Input::get('checkout_at')!= date("Y-m-d")) {
                 $checkout_at = e(Input::get('checkout_at')).' 00:00:00';
        } else {
            $checkout_at = date("Y-m-d H:i:s");
        }

        if (Input::has('expected_checkin')) {
            $expected_checkin = e(Input::get('expected_checkin'));
        } else {
            $expected_checkin = '';
        }


        if ($asset->checkOutToUser($user, $admin, $checkout_at, $expected_checkin, e(Input::get('note')), e(Input::get('name')))) {
          // Redirect to the new asset page
            return Redirect::to("hardware")->with('success', trans('admin/hardware/message.checkout.success'));
        }

      // Redirect to the asset management page with error
        return Redirect::to("hardware/$assetId/checkout")->with('error', trans('admin/hardware/message.checkout.error'))->withErrors($asset->getErrors());
    }


    /**
    * Returns a view that presents a form to check an asset back into inventory.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @param string $backto
    * @since [v1.0]
    * @return View
    */
    public function getCheckin($assetId, $backto = null)
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find($assetId))) {
            // Redirect to the asset management page with error
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }
        $statusLabel_list = Helper::statusLabelList();
        return View::make('hardware/checkin', compact('asset'))->with('statusLabel_list', $statusLabel_list)->with('backto', $backto);
    }


    /**
    * Validate and process the form data to check an asset back into inventory.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return Redirect
    */
    public function postCheckin(AssetCheckinRequest $request, $assetId = null, $backto = null)
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find($assetId))) {
            // Redirect to the asset management page with error
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        // Check for a valid user to checkout fa-random
        // This will need to be tweaked for checkout to location
        if (!is_null($asset->assigned_to)) {
            $user = User::find($asset->assigned_to);
        } else {
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.checkin.already_checked_in'));
        }

        // This is just used for the redirect
        $return_to = $asset->assigned_to;

        $logaction = new Actionlog();
        $logaction->checkedout_to = $asset->assigned_to;

        // Update the asset data to null, since it's being checked in
        $asset->assigned_to                 = null;
        $asset->accepted                  = null;

        $asset->expected_checkin = null;
        $asset->last_checkout = null;

        if (Input::has('status_id')) {
            $asset->status_id =  e(Input::get('status_id'));
        }
        // Was the asset updated?
        if ($asset->save()) {

            if (Input::has('checkin_at')) {

                if (!strtotime(Input::get('checkin_at'))) {
                    $logaction->created_at = date("Y-m-d H:i:s");
                } elseif (Input::get('checkin_at')!= date("Y-m-d")) {
                    $logaction->created_at = e(Input::get('checkin_at')).' 00:00:00';
                }
            }

            $logaction->asset_id = $asset->id;
            $logaction->location_id = null;
            $logaction->asset_type = 'hardware';
            $logaction->note = e(Input::get('note'));
            $logaction->user_id = Auth::user()->id;
            $log = $logaction->logaction('checkin from');
            $settings = Setting::getSettings();

            if ($settings->slack_endpoint) {

                $slack_settings = [
                    'username' => $settings->botname,
                    'channel' => $settings->slack_channel,
                    'link_names' => true
                ];

                $client = new \Maknz\Slack\Client($settings->slack_endpoint, $slack_settings);

                try {
                        $client->attach([
                            'color' => 'good',
                            'fields' => [
                                [
                                    'title' => 'Checked In:',
                                    'value' => strtoupper($logaction->asset_type).' asset <'.config('app.url').'/hardware/'.$asset->id.'/view'.'|'.e($asset->showAssetName()).'> checked in by <'.config('app.url').'/hardware/'.$asset->id.'/view'.'|'.e(Auth::user()->fullName()).'>.'
                                ],
                                [
                                    'title' => 'Note:',
                                    'value' => e($logaction->note)
                                ],

                            ]
                        ])->send('Asset Checked In');

                } catch (Exception $e) {

                }

            }

            $data['log_id'] = $logaction->id;
            $data['first_name'] = $user->first_name;
            $data['item_name'] = $asset->showAssetName();
            $data['checkin_date'] = $logaction->created_at;
            $data['item_tag'] = $asset->asset_tag;
            $data['item_serial'] = $asset->serial;
            $data['note'] = $logaction->note;

            if ((($asset->checkin_email()=='1')) && ($user) && (!config('app.lock_passwords'))) {
                Mail::send('emails.checkin-asset', $data, function ($m) use ($user) {
                    $m->to($user->email, $user->first_name . ' ' . $user->last_name);
                    $m->subject('Confirm Asset Checkin');
                });
            }

            if ($backto=='user') {
                return Redirect::to("admin/users/".$return_to.'/view')->with('success', trans('admin/hardware/message.checkin.success'));
            } else {
                return Redirect::to("hardware")->with('success', trans('admin/hardware/message.checkin.success'));
            }

        }

        // Redirect to the asset management page with error
        return Redirect::to("hardware")->with('error', trans('admin/hardware/message.checkin.error'));
    }


    /**
    * Returns a view that presents information about an asset for detail view.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return View
    */
    public function getView($assetId = null)
    {
        $asset = Asset::withTrashed()->find($assetId);
        $settings = Setting::getSettings();

        if (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        } elseif ($asset->userloc) {
            $use_currency = $asset->userloc->currency;
        } elseif ($asset->assetloc) {
            $use_currency = $asset->assetloc->currency;
        } else {
            $default_currency = Setting::first()->default_currency;

            if ($settings->default_currency!='') {
                $use_currency = $settings->default_currency;
            } else {
                $use_currency = trans('general.currency');
            }

        }

        if (isset($asset->id)) {



            $qr_code = (object) array(
                'display' => $settings->qr_code == '1',
                'url' => route('qr_code/hardware', $asset->id)
            );

            return View::make('hardware/view', compact('asset', 'qr_code', 'settings'))->with('use_currency', $use_currency);
        } else {
            // Prepare the error message
            $error = trans('admin/hardware/message.does_not_exist', compact('id'));

            // Redirect to the user management page
            return Redirect::route('hardware')->with('error', $error);
        }

    }

    /**
    * Return a QR code for the asset
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return Response
    */
    public function getQrCode($assetId = null)
    {
        $settings = Setting::getSettings();

        if ($settings->qr_code == '1') {
            $asset = Asset::find($assetId);
            $size = Helper::barcodeDimensions($settings->barcode_type);

            if (!Company::isCurrentUserHasAccess($asset)) {
                return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
            }

            if (isset($asset->id,$asset->asset_tag)) {
                $barcode = new \Com\Tecnick\Barcode\Barcode();
                $barcode_obj =  $barcode->getBarcodeObj($settings->barcode_type, route('view/hardware', $asset->id), $size['height'], $size['width'], 'black', array(-2, -2, -2, -2));

                return response($barcode_obj->getPngData())->header('Content-type', 'image/png');
            }
        }

    }


    /**
    * Get the Asset import upload page.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @since [v2.0]
    * @return View
    */
    public function getImportUpload()
    {

        $path = config('app.private_uploads').'/imports/assets';
        $files = array();

        if (!Company::isCurrentUserAuthorized()) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        if ($handle = opendir($path)) {

            /* This is the correct way to loop over the directory. */
            while (false !== ($entry = readdir($handle))) {
                clearstatcache();
                if (substr(strrchr($entry, '.'), 1)=='csv') {
                    $files[] = array(
                            'filename' => $entry,
                            'filesize' => Setting::fileSizeConvert(filesize($path.'/'.$entry)),
                            'modified' => filemtime($path.'/'.$entry)
                        );
                }

            }
            closedir($handle);
            $files = array_reverse($files);
        }

        return View::make('hardware/import')->with('files', $files);
    }



    /**
    * Upload the import file via AJAX
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @since [v2.0]
    * @return View
    */
    public function postAPIImportUpload(AssetFileRequest $request)
    {

        if (!Company::isCurrentUserAuthorized()) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));

        } elseif (!config('app.lock_passwords')) {

            $files = Input::file('files');
            $path = config('app.private_uploads').'/imports/assets';
            $results = array();

            foreach ($files as $file) {

                if (!in_array($file->getMimeType(), array(
                    'application/vnd.ms-excel',
                    'text/csv',
                    'text/plain',
                    'text/comma-separated-values',
                    'text/tsv'))) {
                    $results['error']='File type must be CSV';
                    return $results;
                }

                $date = date('Y-m-d-his');
                $fixed_filename = str_replace(' ', '-', $file->getClientOriginalName());
                $file->move($path, $date.'-'.$fixed_filename);
                $name = date('Y-m-d-his').'-'.$fixed_filename;
                $filesize = Setting::fileSizeConvert(filesize($path.'/'.$name));
                $results[] = compact('name', 'filesize');
            }

            return array(
                'files' => $results
            );




        } else {

            $results['error']=trans('general.feature_disabled');
            return $results;
        }



    }


    /**
    * Process the uploaded file
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param string $filename
    * @since [v2.0]
    * @return Redirect
    */
    public function getProcessImportFile($filename)
    {
        // php artisan asset-import:csv path/to/your/file.csv --domain=yourdomain.com --email_format=firstname.lastname

        if (!Company::isCurrentUserAuthorized()) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        $output = new BufferedOutput;
        Artisan::call('asset-import:csv', ['filename'=> config('app.private_uploads').'/imports/assets/'.$filename, '--email_format'=>'firstname.lastname', '--username_format'=>'firstname.lastname'], $output);
        $display_output =  $output->fetch();
        $file = config('app.private_uploads').'/imports/assets/'.str_replace('.csv', '', $filename).'-output-'.date("Y-m-d-his").'.txt';
        file_put_contents($file, $display_output);


        return Redirect::to('hardware')->with('success', 'Your file has been imported');

    }

    /**
    * Returns a view that presents a form to clone an asset.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return View
    */
    public function getClone($assetId = null)
    {
        // Check if the asset exists
        if (is_null($asset_to_clone = Asset::find($assetId))) {
            // Redirect to the asset management page
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($asset_to_clone)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        // Grab the dropdown lists
        $model_list = Helper::modelList();
        $statuslabel_list = Helper::statusLabelList();
        $location_list = Helper::locationsList();
        $manufacturer_list = Helper::manufacturerList();
        $category_list = Helper::categoryList();
        $supplier_list = Helper::suppliersList();
        $assigned_to =Helper::usersList();
        $statuslabel_types = Helper::statusTypeList();
        $company_list = Helper::companyList();

        $asset = clone $asset_to_clone;
        $asset->id = null;
        $asset->asset_tag = '';
        $asset->serial = '';
        $asset->assigned_to = '';

        return View::make('hardware/edit')
        ->with('supplier_list', $supplier_list)
        ->with('model_list', $model_list)
        ->with('statuslabel_list', $statuslabel_list)
        ->with('statuslabel_types', $statuslabel_types)
        ->with('assigned_to', $assigned_to)
        ->with('asset', $asset)
        ->with('location_list', $location_list)
        ->with('manufacturer', $manufacturer_list)
        ->with('category', $category_list)
        ->with('company_list', $company_list);

    }


    /**
    * Retore a deleted asset.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return View
    */
    public function getRestore($assetId = null)
    {

        // Get user information
        $asset = Asset::withTrashed()->find($assetId);

        if (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        } elseif (isset($asset->id)) {

            // Restore the user
            $asset->restore();

            // Prepare the success message
            $success = trans('admin/hardware/message.restore.success');

            // Redirect to the user management page
            return Redirect::route('hardware')->with('success', $success);

        } else {
            return Redirect::to('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        }

    }


    /**
    * Upload a file to the server.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return Redirect
    */
    public function postUpload(AssetFileRequest $request, $assetId = null)
    {

        if (!$asset = Asset::find($assetId)) {
            return Redirect::route('hardware')->with('error', trans('admin/hardware/message.does_not_exist'));
        }

        $destinationPath = config('app.private_uploads').'/assets';



        if (!Company::isCurrentUserHasAccess($asset)) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        }

        if (Input::hasFile('assetfile')) {

            foreach (Input::file('assetfile') as $file) {
                $extension = $file->getClientOriginalExtension();
                $filename = 'hardware-'.$asset->id.'-'.str_random(8);
                $filename .= '-'.str_slug($file->getClientOriginalName()).'.'.$extension;
                $upload_success = $file->move($destinationPath, $filename);

                //Log the deletion of seats to the log
                $logaction = new Actionlog();
                $logaction->asset_id = $asset->id;
                $logaction->asset_type = 'hardware';
                $logaction->user_id = Auth::user()->id;
                $logaction->note = e(Input::get('notes'));
                $logaction->checkedout_to =  null;
                $logaction->created_at =  date("Y-m-d H:i:s");
                $logaction->filename =  $filename;
                $log = $logaction->logaction('uploaded');
            }
        } else {
            return Redirect::back()->with('error', trans('admin/hardware/message.upload.nofiles'));
        }

        if ($upload_success) {
            return Redirect::back()->with('success', trans('admin/hardware/message.upload.success'));
        } else {
            return Redirect::back()->with('error', trans('admin/hardware/message.upload.error'));
        }



    }

    /**
    * Delete the associated file
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param  int  $assetId
    * @param  int  $fileId
    * @since [v1.0]
    * @return View
    */
    public function getDeleteFile($assetId = null, $fileId = null)
    {
        $asset = Asset::find($assetId);
        $destinationPath = config('app.private_uploads').'/imports/assets';

        // the asset is valid
        if (isset($asset->id)) {


            if (!Company::isCurrentUserHasAccess($asset)) {
                return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
            }

            $log = Actionlog::find($fileId);
            $full_filename = $destinationPath.'/'.$log->filename;
            if (file_exists($full_filename)) {
                unlink($destinationPath.'/'.$log->filename);
            }
            $log->delete();
            return Redirect::back()->with('success', trans('admin/hardware/message.deletefile.success'));

        } else {
            // Prepare the error message
            $error = trans('admin/hardware/message.does_not_exist', compact('id'));

            // Redirect to the hardware management page
            return Redirect::route('hardware')->with('error', $error);
        }
    }



    /**
    * Check for permissions and display the file.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param  int  $assetId
    * @param  int  $fileId
    * @since [v1.0]
    * @return View
    */
    public function displayFile($assetId = null, $fileId = null)
    {

        $asset = Asset::find($assetId);

        // the asset is valid
        if (isset($asset->id)) {


            if (!Company::isCurrentUserHasAccess($asset)) {
                return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
            }

            $log = Actionlog::find($fileId);
            $file = $log->get_src('assets');

            $filetype = Helper::checkUploadIsImage($file);

            if ($filetype) {
                  $contents = file_get_contents($file);
                  return Response::make($contents)->header('Content-Type', $filetype);
            } else {
                  return Response::download($file);
            }

        } else {
            // Prepare the error message
            $error = trans('admin/hardware/message.does_not_exist', compact('id'));

            // Redirect to the hardware management page
            return Redirect::route('hardware')->with('error', $error);
        }
    }




    /**
    * Display the bulk edit page.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param  int  $assetId
    * @since [v2.0]
    * @return View
    */
    public function postBulkEdit($assets = null)
    {

        if (!Company::isCurrentUserAuthorized()) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));

        } elseif (!Input::has('edit_asset')) {
                 return Redirect::back()->with('error', 'No assets selected');

        } else {
            $asset_raw_array = Input::get('edit_asset');
            foreach ($asset_raw_array as $asset_id => $value) {
                $asset_ids[] = $asset_id;

            }

        }

        if (Input::has('bulk_actions')) {


            // Create labels
            if (Input::get('bulk_actions')=='labels') {


                $settings = Setting::getSettings();
                if ($settings->qr_code=='1') {

                    $assets = Asset::find($asset_ids);
                    $assetcount = count($assets);
                    $count = 0;

                    return View::make('hardware/labels')->with('assets', $assets)->with('settings', $settings)->with('count', $count)->with('settings', $settings);

                } else {
                  // QR codes are not enabled
                    return Redirect::to("hardware")->with('error', 'Barcodes are not enabled in Admin > Settings');
                }

            } elseif (Input::get('bulk_actions')=='delete') {


                $assets = Asset::with('assigneduser', 'assetloc')->find($asset_ids);
                return View::make('hardware/bulk-delete')->with('assets', $assets);

             // Bulk edit
            } elseif (Input::get('bulk_actions')=='edit') {

                $assets = Input::get('edit_asset');
                $supplier_list = Helper::suppliersList();
                $statuslabel_list = Helper::statusLabelList();
                $location_list = Helper::locationsList();
                $models_list =  Helper::modelList();
                $companies_list = array('' => '') + array('clear' => trans('general.remove_company')) + Helper::companyList();

                return View::make('hardware/bulk')
                ->with('assets', $assets)
                ->with('supplier_list', $supplier_list)
                ->with('statuslabel_list', $statuslabel_list)
                ->with('location_list', $location_list)
                ->with('models_list', $models_list)
                ->with('companies_list', $companies_list);


            }

        } else {
            return Redirect::back()->with('error', 'No action selected');
        }



    }



    /**
    * Save bulk edits
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param  array  $assets
    * @since [v2.0]
    * @return Redirect
    */
    public function postBulkSave($assets = null)
    {

        if (!Company::isCurrentUserAuthorized()) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));

        } elseif (Input::has('bulk_edit')) {

            $assets = Input::get('bulk_edit');

            if ((Input::has('purchase_date')) ||  (Input::has('purchase_cost'))  ||  (Input::has('supplier_id')) ||  (Input::has('order_number')) || (Input::has('warranty_months')) || (Input::has('rtd_location_id'))  || (Input::has('requestable')) ||  (Input::has('company_id')) || (Input::has('status_id')) ||  (Input::has('model_id'))) {

                foreach ($assets as $key => $value) {

                    $update_array = array();

                    if (Input::has('purchase_date')) {
                        $update_array['purchase_date'] =  e(Input::get('purchase_date'));
                    }

                    if (Input::has('purchase_cost')) {
                        $update_array['purchase_cost'] =  e(Input::get('purchase_cost'));
                    }

                    if (Input::has('supplier_id')) {
                        $update_array['supplier_id'] =  e(Input::get('supplier_id'));
                    }

                    if (Input::has('model_id')) {
                        $update_array['model_id'] =  e(Input::get('model_id'));
                    }

                    if (Input::has('company_id')) {
                        if (Input::get('company_id')=="clear") {
                            $update_array['company_id'] =  null;
                        } else {
                            $update_array['company_id'] =  e(Input::get('company_id'));
                        }

                    }

                    if (Input::has('order_number')) {
                        $update_array['order_number'] =  e(Input::get('order_number'));
                    }

                    if (Input::has('warranty_months')) {
                        $update_array['warranty_months'] =  e(Input::get('warranty_months'));
                    }

                    if (Input::has('rtd_location_id')) {
                        $update_array['rtd_location_id'] = e(Input::get('rtd_location_id'));
                    }

                    if (Input::has('status_id')) {
                        $update_array['status_id'] = e(Input::get('status_id'));
                    }

                    if (Input::has('requestable')) {
                        $update_array['requestable'] = e(Input::get('requestable'));
                    }


                    if (DB::table('assets')
                    ->where('id', $key)
                    ->update($update_array)) {

                        $logaction = new Actionlog();
                        $logaction->asset_id = $key;
                        $logaction->asset_type = 'hardware';
                        $logaction->created_at =  date("Y-m-d H:i:s");

                        if (Input::has('rtd_location_id')) {
                            $logaction->location_id = e(Input::get('rtd_location_id'));
                        }
                        $logaction->user_id = Auth::user()->id;
                        $log = $logaction->logaction('update');

                    }

                } // endforeach

                return Redirect::to("hardware")->with('success', trans('admin/hardware/message.update.success'));

            // no values given, nothing to update
            } else {
                return Redirect::to("hardware")->with('info', trans('admin/hardware/message.update.nothing_updated'));

            }


        } // endif

        return Redirect::to("hardware");

    }

    /**
    * Save bulk deleted.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param  array  $assets
    * @since [v2.0]
    * @return View
    */
    public function postBulkDelete($assets = null)
    {

        if (!Company::isCurrentUserAuthorized()) {
            return Redirect::to('hardware')->with('error', trans('general.insufficient_permissions'));
        } elseif (Input::has('bulk_edit')) {
              //$assets = Input::get('bulk_edit');
            $assets = Asset::find(Input::get('bulk_edit'));
          //print_r($assets);


            foreach ($assets as $asset) {
          //echo '<li>'.$asset;
                $update_array['deleted_at'] = date('Y-m-d h:i:s');
                $update_array['assigned_to'] = null;

                if (DB::table('assets')
                ->where('id', $asset->id)
                ->update($update_array)) {

                    $logaction = new Actionlog();
                    $logaction->asset_id = $asset->id;
                    $logaction->asset_type = 'hardware';
                    $logaction->created_at =  date("Y-m-d H:i:s");
                    $logaction->user_id = Auth::user()->id;
                    $log = $logaction->logaction('deleted');

                }

            } // endforeach
                return Redirect::to("hardware")->with('success', trans('admin/hardware/message.delete.success'));

            // no values given, nothing to update
        } else {
            return Redirect::to("hardware")->with('info', trans('admin/hardware/message.delete.nothing_updated'));

        }

        // Something weird happened here - default to hardware
        return Redirect::to("hardware");

    }



    /**
    * Generates the JSON used to display the asset listing.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param  string  $status
    * @since [v2.0]
    * @return String JSON
    */
    public function getDatatable($status = null)
    {


        $assets = Asset::select('assets.*')->with('model', 'assigneduser', 'assigneduser.userloc', 'assetstatus', 'defaultLoc', 'assetlog', 'model', 'model.category', 'model.fieldset', 'assetstatus', 'assetloc', 'company')
        ->Hardware();

        if (Input::has('search')) {
             $assets = $assets->TextSearch(Input::get('search'));
        }

        if (Input::has('offset')) {
             $offset = e(Input::get('offset'));
        } else {
             $offset = 0;
        }

        if (Input::has('limit')) {
             $limit = e(Input::get('limit'));
        } else {
             $limit = 50;
        }

        if (Input::has('order_number')) {
            $assets->where('order_number', '=', e(Input::get('order_number')));
        }

        switch ($status) {
            case 'Deleted':
                $assets->withTrashed()->Deleted();
                break;
            case 'Pending':
                $assets->Pending();
                break;
            case 'RTD':
                $assets->RTD();
                break;
            case 'Undeployable':
                $assets->Undeployable();
                break;
            case 'Archived':
                $assets->Archived();
                break;
            case 'Requestable':
                $assets->RequestableAssets();
                break;
            case 'Deployed':
                $assets->Deployed();
                break;

        }

        $allowed_columns = [
        'id',
        'name',
        'asset_tag',
        'serial',
        'model',
        'last_checkout',
        'category',
        'notes',
        'expected_checkin',
        'order_number',
        'companyName',
        'location',
        'image',
        'status_label',
        'assigned_to'
        ];

        $all_custom_fields=CustomField::all(); //used as a 'cache' of custom fields throughout this page load

        foreach ($all_custom_fields as $field) {
            $allowed_columns[]=$field->db_column_name();
        }

        $order = Input::get('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array(Input::get('sort'), $allowed_columns) ? Input::get('sort') : 'asset_tag';

        switch ($sort) {
            case 'model':
                $assets = $assets->OrderModels($order);
                break;
            case 'category':
                $assets = $assets->OrderCategory($order);
                break;
            case 'companyName':
                $assets = $assets->OrderCompany($order);
                break;
            case 'location':
                $assets = $assets->OrderLocation($order);
                break;
            case 'status_label':
                $assets = $assets->OrderStatus($order);
                break;
            case 'assigned_to':
                $assets = $assets->OrderAssigned($order);
                break;
            default:
                $assets = $assets->orderBy($sort, $order);
                break;
        }

        $assetCount = $assets->count();
        $assets = $assets->skip($offset)->take($limit)->get();


        $rows = array();
        foreach ($assets as $asset) {
            $inout = '';
            $actions = '';
            if ($asset->deleted_at=='') {
                $actions = '<div style=" white-space: nowrap;"><a href="'.route('clone/hardware', $asset->id).'" class="btn btn-info btn-sm" title="Clone asset" data-toggle="tooltip"><i class="fa fa-clone"></i></a> <a href="'.route('update/hardware', $asset->id).'" class="btn btn-warning btn-sm" title="Edit asset" data-toggle="tooltip"><i class="fa fa-pencil icon-white"></i></a> <a data-html="false" class="btn delete-asset btn-danger btn-sm" data-toggle="modal" href="'.route('delete/hardware', $asset->id).'" data-content="'.trans('admin/hardware/message.delete.confirm').'" data-title="'.trans('general.delete').' '.htmlspecialchars($asset->asset_tag).'?" onClick="return false;"><i class="fa fa-trash icon-white"></i></a></div>';
            } elseif ($asset->model->deleted_at=='') {
                $actions = '<a href="'.route('restore/hardware', $asset->id).'" title="Restore asset" data-toggle="tooltip" class="btn btn-warning btn-sm"><i class="fa fa-recycle icon-white"></i></a>';
            }

            if ($asset->assetstatus) {
                if ($asset->assetstatus->deployable != 0) {
                    if (($asset->assigned_to !='') && ($asset->assigned_to > 0)) {
                        $inout = '<a href="'.route('checkin/hardware', $asset->id).'" class="btn btn-primary btn-sm" title="Checkin this asset" data-toggle="tooltip">'.trans('general.checkin').'</a>';
                    } else {
                        $inout = '<a href="'.route('checkout/hardware', $asset->id).'" class="btn btn-info btn-sm" title="Checkout this asset to a user" data-toggle="tooltip">'.trans('general.checkout').'</a>';
                    }
                }
            }

            $row = array(
            'checkbox'      =>'<div class="text-center"><input type="checkbox" name="edit_asset['.$asset->id.']" class="one_required"></div>',
            'id'        => $asset->id,
            'image' => (($asset->image) && ($asset->image!='')) ? '<img src="'.config('app.url').'/uploads/assets/'.$asset->image.'" height=50 width=50>' : ((($asset->model) && ($asset->model->image!='')) ? '<img src="'.config('app.url').'/uploads/models/'.$asset->model->image.'" height=40 width=50>' : ''),
            'name'          => '<a title="'.e($asset->name).'" href="hardware/'.$asset->id.'/view">'.e($asset->name).'</a>',
            'asset_tag'     => '<a title="'.e($asset->asset_tag).'" href="hardware/'.$asset->id.'/view">'.e($asset->asset_tag).'</a>',
            'serial'        => e($asset->serial),
            'model'         => ($asset->model) ? (string)link_to('/hardware/models/'.$asset->model->id.'/view', e($asset->model->name)) : 'No model',
            'status_label'        => ($asset->assigneduser) ? 'Deployed' : ((e($asset->assetstatus)) ? e($asset->assetstatus->name) : ''),
            'assigned_to'        => ($asset->assigneduser) ? (string)link_to('../admin/users/'.$asset->assigned_to.'/view', e($asset->assigneduser->fullName())) : '',
            'location'      => (($asset->assigneduser) && ($asset->assigneduser->userloc!='')) ? (string)link_to('admin/settings/locations/'.$asset->assigneduser->userloc->id.'/edit', e($asset->assigneduser->userloc->name)) : (($asset->defaultLoc!='') ? (string)link_to('admin/settings/locations/'.$asset->defaultLoc->id.'/edit', e($asset->defaultLoc->name)) : ''),
            'category'      => (($asset->model) && ($asset->model->category)) ? e($asset->model->category->name) : '',
            'eol'           => ($asset->eol_date()) ? $asset->eol_date() : '',
            'notes'         => e($asset->notes),
            'order_number'  => ($asset->order_number!='') ? '<a href="'.config('app.url').'/hardware?order_number='.e($asset->order_number).'">'.e($asset->order_number).'</a>' : '',
            'last_checkout' => ($asset->last_checkout!='') ? e($asset->last_checkout) : '',
            'expected_checkin' => ($asset->expected_checkin!='')  ? e($asset->expected_checkin) : '',
            'change'        => ($inout) ? $inout : '',
            'actions'       => ($actions) ? $actions : '',
            'companyName'   => is_null($asset->company) ? '' : e($asset->company->name)
            );
            foreach ($all_custom_fields as $field) {
                $row[$field->db_column_name()]=$asset->{$field->db_column_name()};
            }
            $rows[]=$row;
        }

        $data = array('total'=>$assetCount, 'rows'=>$rows);

        return $data;
    }
}
