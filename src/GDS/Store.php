<?php
/**
 * Copyright 2014 Tom Walder
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace GDS;

/**
 * A Kind-specific data store.
 *
 * MUST be for a specific Entity Kind (as defined by the Schema or Kind passed in on construction)
 *
 * @author Tom Walder <tom@docnet.nu>
 */
class Store
{

    /**
     * The GDS Gateway we're going to use
     *
     * @var Gateway
     */
    private $obj_gateway = NULL;

    /**
     * The GDS Schema defining the Entity we're operating with
     *
     * @var Schema
     */
    private $obj_schema = NULL;

    /**
     * The last GQL query
     *
     * @var string|null
     */
    private $str_last_query = NULL;

    /**
     * Named parameters for the last query
     *
     * @var array|null
     */
    private $arr_last_params = NULL;

    /**
     * The last result cursor
     *
     * @var string|null
     */
    private $str_last_cursor = NULL;

    /**
     * The class to use when instantiating new Entity objects
     *
     * @var string
     */
    private $str_entity_class = '\\GDS\\Entity';

    /**
     * Transaction ID
     *
     * @var null|string
     */
    private $str_transaction_id = NULL;

    /**
     * Gateway and Schema/Kind can be supplied on construction
     *
     * @todo We'll do our best to use sensible defaults
     *
     * @param Schema|string|null $mix_schema
     * @param Gateway $obj_gateway
     * @throws \Exception
     */
    public function __construct($mix_schema = NULL, Gateway $obj_gateway = NULL)
    {
        $this->obj_schema = $this->determineSchema($mix_schema);
        $this->obj_gateway = (NULL === $obj_gateway) ? new \GDS\Gateway\ProtoBuf() : $obj_gateway;
        $this->str_last_query = 'SELECT * FROM `' . $this->obj_schema->getKind() . '` ORDER BY __key__ ASC';
    }

    /**
     * Set up the Schema for the current data model, based on the provided Kind/Schema/buildSchema
     *
     * @param Schema|string|null $mix_schema
     * @return Schema
     * @throws \Exception
     */
    private function determineSchema($mix_schema)
    {
        if(NULL === $mix_schema) {
            $mix_schema = $this->buildSchema();
        }
        if ($mix_schema instanceof Schema) {
            return $mix_schema;
        }
        if (is_string($mix_schema)) {
            return new Schema($mix_schema);
        }
        throw new \Exception('You must provide a Schema or Kind. Alternatively, you can extend GDS\Store and implement the buildSchema() method.');
    }

    /**
     * Write one or more new/changed Entity objects to the Datastore
     *
     * @param Entity|Entity[]
     */
    public function upsert($arr_entities)
    {
        if($arr_entities instanceof Entity) {
            $arr_entities = [$arr_entities];
        }
        $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->consumeTransaction())
            ->putMulti($arr_entities);
    }

    /**
     * Delete one or more Model objects from the Datastore
     *
     * @param mixed
     * @return bool
     */
    public function delete($arr_entities)
    {
        if($arr_entities instanceof Entity) {
            $arr_entities = [$arr_entities];
        }
        return $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->consumeTransaction())
            ->deleteMulti($arr_entities);
    }

    /**
     * Fetch a single Entity from the Datastore, by it's Key ID
     *
     * Only works for root Entities (i.e. those without parent Entities)
     *
     * @param $str_id
     * @return Entity|null
     */
    public function fetchById($str_id)
    {
        return $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->fetchById($str_id);
    }

    /**
     * Fetch multiple entities by Key ID
     *
     * @param $arr_ids
     * @return Entity[]
     */
    public function fetchByIds(array $arr_ids)
    {
        return $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->fetchByIds($arr_ids);
    }

    /**
     * Fetch a single Entity from the Datastore, by it's Key Name
     *
     * Only works for root Entities (i.e. those without parent Entities)
     *
     * @param $str_name
     * @return Entity|null
     */
    public function fetchByName($str_name)
    {
        return $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->fetchByName($str_name);
    }

    /**
     * Fetch one or more Entities from the Datastore, by their Key Name
     *
     * Only works for root Entities (i.e. those without parent Entities)
     *
     * @param $arr_names
     * @return Entity|null
     */
    public function fetchByNames(array $arr_names)
    {
        return $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->fetchByNames($arr_names);
    }

    /**
     * Fetch Entities based on a GQL query
     *
     * Convert any Entity parameters into Keys using the Mapper
     *
     * @todo FIXME, using v1.x Mapper
     *
     * @param $str_query
     * @param array|null $arr_params
     * @return Entity[]
     */
    public function query($str_query, $arr_params = NULL)
    {
        $this->str_last_query = $str_query;
        if(is_array($arr_params)) {
            foreach($arr_params as $str_key => $mix_val) {
                if($mix_val instanceof Entity) {
                    $arr_params[$str_key] = $this->obj_mapper->createKey($mix_val);
                }
            }
        }
        $this->arr_last_params = $arr_params;
        $this->str_last_cursor = NULL;
        return $this;
    }

    /**
     * Fetch ONE Entity based on a GQL query
     *
     * @todo FIXME, mapping
     *
     * @param $str_query
     * @param array|null $arr_params
     * @return Entity
     */
    public function fetchOne($str_query = NULL, $arr_params = NULL)
    {
        if(NULL !== $str_query) {
            $this->query($str_query, $arr_params);
        }
        $arr_results = $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->gql($this->str_last_query . ' LIMIT 1', $this->arr_last_params);
        return $this->mapOneFromResults($arr_results);
    }

    /**
     * Fetch Entities (optionally based on a GQL query)
     *
     * @todo FIXME, mapping
     *
     * @param $str_query
     * @param array|null $arr_params
     * @return Entity[]
     */
    public function fetchAll($str_query = NULL, $arr_params = NULL)
    {
        if(NULL !== $str_query) {
            $this->query($str_query, $arr_params);
        }
        $arr_results = $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->gql($this->str_last_query, $this->arr_last_params);
        return $this->mapFromResults($arr_results);
    }

    /**
     * Fetch (a page of) Entities (optionally based on a GQL query)
     *
     * @todo FIXME, mapping
     *
     * @param $int_page_size
     * @param null $mix_offset
     * @return Entity[]
     */
    public function fetchPage($int_page_size, $mix_offset = NULL)
    {
        $str_offset = '';
        $arr_params = (array)$this->arr_last_params;
        if(NULL !== $mix_offset) {
            if(is_int($mix_offset)) {
                $str_offset = 'OFFSET @intOffset';
                $arr_params['intOffset'] = $mix_offset;
            } else {
                $str_offset = 'OFFSET @startCursor';
                $arr_params['startCursor'] = $mix_offset;
            }
        } else if (strlen($this->str_last_cursor) > 1) {
            $str_offset = 'OFFSET @startCursor';
            $arr_params['startCursor'] = $this->str_last_cursor;
        }
        if(empty($arr_params)) {
            $arr_params = NULL;
        }
        $arr_results = $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->gql($this->str_last_query . " LIMIT {$int_page_size} {$str_offset}", $arr_params);
        $this->str_last_cursor = $this->obj_gateway->getEndCursor();
        return $this->mapFromResults($arr_results);
    }

    /**
     * Fetch all of the entities in a particular group
     *
     * @todo FIXME, mapping
     *
     * @param Entity $obj_entity
     * @return Entity[]
     */
    public function fetchEntityGroup(Entity $obj_entity)
    {
        $arr_results = $this->obj_gateway
            ->withSchema($this->obj_schema)
            ->withTransaction($this->str_transaction_id)
            ->gql("SELECT * FROM `" . $this->obj_schema->getKind() . "` WHERE __key__ HAS ANCESTOR @ancestorKey", [
                'ancestorKey' => $this->obj_mapper->createKey($obj_entity)
            ]);
        $this->str_last_cursor = $this->obj_gateway->getEndCursor();
        return $this->mapFromResults($arr_results);
    }

    /**
     * Get the last result cursor
     *
     * @return null|string
     */
    public function getCursor()
    {
        return $this->str_last_cursor;
    }

    /**
     * Set the query cursor
     *
     * Usually before continuing through a paged result set
     *
     * @param $str_cursor
     * @return $this
     */
    public function setCursor($str_cursor)
    {
        $this->str_last_cursor = $str_cursor;
        return $this;
    }

    /**
     * Create a new instance of this GDS Entity class
     *
     * @param array|null $arr_data
     * @return Entity
     */
    public final function createEntity($arr_data = NULL)
    {
        $obj_entity = (new $this->str_entity_class())->setSchema($this->obj_schema);
        if(NULL !== $arr_data) {
            foreach ($arr_data as $str_property => $mix_value) {
                $obj_entity->__set($str_property, $mix_value);
            }
        }
        return $obj_entity;
    }

    /**
     * Set the class to use when instantiating new Entity objects
     *
     * Must be GDS\Entity, or a sub-class of it
     *
     * @param $str_class
     * @return $this
     * @throws \Exception
     */
    public final function setEntityClass($str_class)
    {
        if(class_exists($str_class)) {
            if(is_a($str_class, '\\GDS\\Entity', TRUE)) {
                $this->str_entity_class = $str_class;

                // @todo see if this makes sense...
                $this->obj_schema->setEntityClass($str_class);

            } else {
                throw new \Exception('Cannot set an Entity class that does not extend "GDS\Entity": ' . $str_class);
            }
        } else {
            throw new \Exception('Cannot set missing Entity class: ' . $str_class);
        }
        return $this;
    }

    /**
     * Begin a transaction
     *
     * @param bool $bol_cross_group
     * @return $this
     */
    public function beginTransaction($bol_cross_group = FALSE)
    {
        $this->str_transaction_id = $this->obj_gateway->beginTransaction($bol_cross_group);
        return $this;
    }

    /**
     * Clear and return the current transaction ID
     *
     * @return string|null
     */
    private function consumeTransaction()
    {
        $str_transaction_id = $this->str_transaction_id;
        $this->str_transaction_id = NULL;
        return $str_transaction_id;
    }

    /**
     * Optionally build and return a Schema object describing the data model
     *
     * This method is intended to be overridden in any extended Store classes
     *
     * @return Schema|null
     */
    protected function buildSchema()
    {
        return NULL;
    }

}