<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class ChargifyCallOut extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('chargify');
        $this->load->model('chargify_model');
    }

    public function upsertSubscriptions() {
        $subscription_table = array();
        //Set domains using the class var $subdomains. 
        $this->chargify->setDomain($this->subdomains[$i]);
        //array use for to store processed records from the various API calls to chargify
        $subscriptions = $this->chargify->get_subscriptions();
        //When no subscriptions exist chargify returns false, this control statement starts the loop again us to the start
        //of the for statement.
        if ($subscriptions == false) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $subscription_components = $this->chargify->get_subscription_components($subscription->id);
            if ($subscription->state == "past_due" || $subscription->state == "canceled") {
                $billing_status = "past_due";
            } else {
                $billing_status = "current";
            }

            //Iterate through each component returned and remove any that have no allocation to the subscription 
            foreach ($subscription_components as $subscription_component => $individualSubComponent) {
                if ($individualSubComponent->allocated_quantity <= 0) {
                    unset($subscription_components[$subscription_component]);
                }// END REMOVE SUBSCRIPTION IF
            }//END FOREACH    
            //Check that there are elements remaining from the above logic, otherwise set multi org to false.
            if (count($subscription_components) > 0) {
                //Iterate through the array and check if the subscription id and the component name suggest the subscriber have multi_org enabled
                foreach ($subscription_components as $subscription_component) {
                    if ($subscription_component->name != "Multi-Org Monthly Subscription" ||
                            $subscription_component->name != "Multi-Org Subscription" &&
                            $subscription_component->subscription_id != $subscription->id) {
                        $multi_org = "false";
                        $invoice_tier = $subscription_component->allocated_quantity;
                    } else {
                        $multi_org = 'true';
                        $invoice_tier = 0;
                    }//END NESTED IF
                }//END FOREACH
            } else {
                $multi_org = 'false';
                $invoice_tier = 0;
            }//END COMPONENT SIZE CHECK IF
            //This is essentially an arraylist $subscription_table = Postgres_subscriptions table.
            //The pushed elements are row informtation taken from the various API calls throughout this
            //method.
            array_push($subscription_table, array(
                "subscription_id" => (string) $subscription->id,
                "sf_org_id" => $subscription->customer->reference,
                "customer_id" => (string) $subscription->customer->id,
                "app" => $app,
                "platform" => "Chargify",
                "account_state" => ($subscription->state == "canceled") ? "canceled" : "active",
                "billing_status" => $billing_status,
                "billing_method" => $subscription->payment_collection_method,
                "alert_message" => $subscription->cancellation_message,
                "billing_frequency" => $billing_frequency,
                "plan_name" => $subscription->product->name,
                "currency" => $currency,
                "current_price" => ($subscription->product_price_in_cents / 100),
                "multi_org" => $multi_org,
                "billing_token" => null,
                "invoice_tier" => $invoice_tier,
                "sub_domain" => $this->subdomains[$i]
            ));
        }
        //Check that there are actually elements in the array otherwise we would create a malformed SQL query
        if (array_filter($subscription_table)) {
            $this->chargify_model->doesRecordExist($subscription_table, "subscriptions", "subscription_id");
        }
    }

//END upsertSubscriptions() FUNCTION

    private function isValidRequest() {
        $msg = new Msg();
        $auth_token = "";
        if (!empty($this->input->get_request_header("Auth-Token", TRUE))) {
            $auth_token = $this->input->get_request_header("Auth-Token", TRUE);
        } else if (!empty($this->input->get_request_header("Auth-token", TRUE))) {
            $auth_token = $this->input->get_request_header("Auth-token", TRUE);
        }
        if ($auth_token !== AUTH_TOKEN) {
            return new Msg(array("Error" => error("Unauthorised Access"), "code" => 401), TRUE);
        }
        return $msg;
    }

    public function subscriptionStatus($accountId = '', $app = '') {
        header('Content-type: application/json');
        try {
            $this->load->library("Msg");
            $msg = $this->isValidRequest();
            if (!$msg->is_error) {
                $this->load->model("api_v2_response_model");
                //Change array values to new response values
                if (valid($accountId) && $this->input->get_request_header("Auth-Token", TRUE) === AUTH_TOKEN) {
                    $subStatuses = $this->api_v2_response_model->getRecords(array("org_id" => $accountId, "app" => $app), "api_v2_subscription_response");
                    if (count($subStatuses) > 0) {
                        $data = array();
                        foreach ($subStatuses as $subStatus) {
                            $data["account"]["plan_code"] = $subStatus->plan_code;
                            $data["account"]["plan_name"] = $subStatus->plan_name;
                            $data["account"]["invoice_tier"] = $subStatus->invoice_tier;
                            $data["account"]["multi_org"] = $subStatus->multi_org;
                            $data["account"]["status"] = $subStatus->status;
                            $data["account"]["expiration"] = $subStatus->date_of_expiration;
                            $data["account"]["billing_status"] = $subStatus->billing_status;
                            $data["account"]["active"] = $subStatus->active;
                            //$data["account"]["accountid"] = $accountId;
                            $data["account"]["days_overdue"] = $subStatus->days_overdue;
                            $data["account"]["hosted_token"] = 0;
                            $data["account"]["hosted_token"] = valid($subStatus->billing_token) ? $subStatus->billing_token : null;
                        }
                        echo '{"version":"v2","result":' . json_encode($data) . '}';
                    } else {
                        http_response_code(404);
                        echo '{"version":"v2","result":"Failed"}';
                    }
                } else {
                    http_response_code(404);
                    echo '{"version":"v2","result":"Resource not found"}';
                }
            } else {
                http_response_code(404);
                echo '{"version":"v2","result":"Resource not found"}';
            }
        } catch (Exception $ex) {
            http_response_code(400);
            echo '{"version":"v2","result":"Failed : ' . $ex->getMessage() . '"}';
        }
    }

    private function updateAllocatedQuantity() {
        //CODE TO BE ADDED WHEN AUTOUPDATING CLIENT INVOICE USAGE 
    }

}

/* End of file welcome.php */
    /* Location: ./application/controllers/welcome.php */    
