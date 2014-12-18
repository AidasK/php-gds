<?php
/**
 * GDS Gateway
 *
 * Persists and retrieves Entities to/from GDS
 *
 * @author Tom Walder <tom@docnet.nu>
 */
namespace GDS;

/**
 * Google Datastore Gateway
 *
 * @package GDS
 */
class Gateway
{

    /**
     * @var \Google_Service_Datastore_Datasets_Resource|null
     */
    private $obj_datasets = NULL;

    /**
     * @var string
     */
    private $str_dataset_id = NULL;

    /**
     * Create a new GDS service
     *
     * @param \Google_Client $obj_client
     * @param $str_dataset_id
     */
    public function __construct(\Google_Client $obj_client, $str_dataset_id)
    {
        $obj_service = new \Google_Service_Datastore($obj_client);
        $this->obj_datasets = $obj_service->datasets;
        $this->str_dataset_id = $str_dataset_id;
    }

    /**
     * Create a configured Google Client ready for Datastore use
     *
     * @param $str_app_name
     * @param $str_service_account
     * @param $str_key_file
     * @return \Google_Client
     */
    public static function createGoogleClient($str_app_name, $str_service_account, $str_key_file)
    {
        $obj_client = new \Google_Client();
        $obj_client->setApplicationName($str_app_name);
        $str_key = file_get_contents($str_key_file);
        $obj_client->setAssertionCredentials(
            new \Google_Auth_AssertionCredentials(
                $str_service_account,
                [\Google_Service_Datastore::DATASTORE, \Google_Service_Datastore::USERINFO_EMAIL],
                $str_key
            )
        );
        return $obj_client;
    }

    /**
     * Put an Entity into the Datastore
     *
     * @todo Transactions
     *
     * @param \Google_Service_Datastore_Entity $obj_entity
     * @param $bol_auto_id
     * @return bool
     */
    public function put(\Google_Service_Datastore_Entity $obj_entity, $bol_auto_id)
    {
        $obj_mutation = new \Google_Service_Datastore_Mutation();
        if ($bol_auto_id) {
            $obj_mutation->setInsertAutoId([$obj_entity]);
        } else {
            $obj_mutation->setUpsert([$obj_entity]);
        }
        $obj_request = new \Google_Service_Datastore_CommitRequest();
        $obj_request->setMode('NON_TRANSACTIONAL');
        $obj_request->setMutation($obj_mutation);

        /** @var \Google_Service_Datastore_CommitResponse $obj_response */
        $obj_response = $this->obj_datasets->commit($this->str_dataset_id, $obj_request);
        return(1 == $obj_response->getMutationResult()['indexUpdates']);
    }

    /**
     * Fetch entity data by Key ID
     *
     * @param $str_kind
     * @param $str_key_id
     * @return array
     */
    public function fetchById($str_kind, $str_key_id)
    {
        $obj_path = new \Google_Service_Datastore_KeyPathElement();
        $obj_path->setKind($str_kind);
        $obj_path->setId($str_key_id);
        $obj_key = new \Google_Service_Datastore_Key();
        $obj_key->setPath([$obj_path]);
        return $this->fetchByKeys([$obj_key]);
    }

    /**
     * Fetch entity data by Key Name
     *
     * @param $str_kind
     * @param $str_key_name
     * @return mixed
     */
    public function fetchByName($str_kind, $str_key_name)
    {
        $obj_path = new \Google_Service_Datastore_KeyPathElement();
        $obj_path->setKind($str_kind);
        $obj_path->setName($str_key_name);
        $obj_key = new \Google_Service_Datastore_Key();
        $obj_key->setPath([$obj_path]);
        return $this->fetchByKeys([$obj_key]);
    }

    /**
     * Fetch entity data for an array of Keys
     *
     * @param $arr_keys
     * @return mixed
     */
    private function fetchByKeys($arr_keys)
    {
        $obj_request = new \Google_Service_Datastore_LookupRequest();
        $obj_request->setKeys($arr_keys);
        $obj_response = $this->obj_datasets->lookup($this->str_dataset_id, $obj_request);
        return $obj_response->getFound();
    }

    /**
     * Fetch entity data based on GQL
     *
     * @param $str_gql
     * @return Model[]
     */
    public function gql($str_gql)
    {
        $obj_query = new \Google_Service_Datastore_GqlQuery();
        $obj_query->setAllowLiteral(TRUE);
        $obj_query->setQueryString($str_gql);
        return $this->executeQuery($obj_query);
    }

    /**
     * Execute the given query and return the results.
     *
     * @param \Google_Collection $obj_query
     * @return array
     */
    private function executeQuery(\Google_Collection $obj_query)
    {
        $obj_request = new \Google_Service_Datastore_RunQueryRequest();
        if ($obj_query instanceof \Google_Service_Datastore_GqlQuery) {
            $obj_request->setGqlQuery($obj_query);
        } else {
            $obj_request->setQuery($obj_query);
        }
        $obj_response = $this->obj_datasets->runQuery($this->str_dataset_id, $obj_request);
        if (isset($obj_response['batch']['entityResults'])) {
            return $obj_response['batch']['entityResults'];
        }
        return [];
    }

}