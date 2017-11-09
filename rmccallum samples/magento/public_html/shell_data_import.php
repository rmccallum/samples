#!/usr/bin/php
<?php

/**
 * ARGS:
 * 0 - filename
 * 1 - config file
 * 2 - input file
 * 3 - start batch counter | default to 0
 */

ini_set('memory_limit', '11264M'); // This one goes to 11
ini_set('max_execution_time', '3600');

chdir(dirname(__FILE__));

// Set up Magento
require 'app/bootstrap.php';
require 'app/Mage.php';
if (!Mage::isInstalled()) {
    echo "Application is not installed yet, please complete install wizard first.";
    exit;
}
Mage::app('admin')->setUseSessionInUrl(false);
umask(0);
Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

$config_json = json_decode(file_get_contents($argv[1]));
$input_file = $argv[2];

$configurableProducts = [];
$simpleProducts = [];
$newColours = [];
$newBrands = [];
$existingColours = [];
$existingBrands = [];
$fields = [];
$variants = [];
$_invalid_attributes_flag = false;
$line = 0;
$tmp_name = '';
$tmp_sku = '';
$tmp_brand = '';
$tmp_color = '';
$style_1 = '';
$style_2 = '';
$style_3 = '';
$_should_batch = $config_json->batching->should_batch;
$start_batch = 0;
$batch_limit = 0;
$counter = 0;

if ($_should_batch) {
    $start_batch = (count($argv) > 3) ? intval($argv[3]) : 0;
    $batch_limit = ($start_batch +1) * intval($config_json->batching->batchLimit);
    $start_batch = $start_batch * intval($config_json->batching->batchLimit) +1;
}



$attribute_set_id = $config_json->attribute_set_id;

// These attributes will be used to build the configurable product
$configurable_options = $config_json->configurable_options;
$configurable_option_ids = [];
foreach ($configurable_options as $option) {
    $configurable_option_ids[] = getAttributeId($option);
}

// These Attributes will be used to determine if attribute options need added from the importing CSV
$_attribute_options = $config_json->attribute_set_options;

/**
 * Generates an SKU for a product based on the defined attributes and options
 * getSimpleSku
 * Parameters:
 * @param   (Array) The array of product information
 * @param   (Sting) The initial SKU of the configurable product
 *
 * @return  (String)    The generated SKU
 */
function getSimpleSku($product, $sku)
{
    global $config_json;

    $part_length = 3;
    if ($config_json->sku_part_length) {
        $part_length = intval($config_json->sku_part_length);
    }

    $this_sku = '';
    foreach (explode('-', $sku) as $partial) {
        $this_sku .= substr(str_replace(['/','.'], '', $partial), 0, $part_length);
    }

    foreach ($config_json->generate_sku as $part) {
        $this_sku .= '-' . strtolower(str_replace(array(' ', '/'), array('-', ''), $product[$part]));
    }
    return $this_sku;
}

/**
 * generates a name if required
 *
 * @param $simple
 * @return string
 */
function getName($simple)
{
    global $config_json;
    $this_name = $simple['name'];
    if ($config_json->name_required) {

        if ($config_json->brand_required) {
            $this_name = $simple['brand'];
        }

        foreach ($config_json->generate_name as $part) {
            $this_name .= ' ' . $simple[$part];
        }
    }

    return $this_name;
}

/**
 * Returns the Attribute ID from the Attribute Text value
 */
function getAttributeId($attribute_text)
{
    $productModel = Mage::getModel('catalog/product');
    $attr = $productModel->getResource()->getAttribute($attribute_text);
    return $attr->getId();
}

/**
 * Returns the attribute option value ID based on the attribute and option label
 * If a value if not found, 1 is cretaed and the global flag for invalid
 * attribute options is set. This prevents the script executing until all
 * possible attribute values have been stored from the incoming CSV file
 *
 * @param       (String) Attribute Code Label
 * @param       (String) Attribute Option Label
 *
 * @return     (Int) The Option value ID, false otherwise
 */
function getAttributeValueIdFromCode($attribute_text, $label, $display = false)
{
    global $_invalid_attributes_flag;

    $productModel = Mage::getModel('catalog/product');

    $attr = $productModel->getResource()->getAttribute($attribute_text);
    $option_id = null;

    try {

        if ($display) {
            var_dump($attr);
            exit;
        }
        
        if ($attr->usesSource()) {
            $option_id = $attr->getSource()->getOptionId($label);
        }

        if (!is_null($option_id)) {
            return $option_id;
        }

        // Option does not exist
        // Create it and recursively call it

        $arg_attribute = $attribute_text;
        $arg_value = $label;

        $attr_model = Mage::getModel('catalog/resource_eav_attribute');
        $attr = $attr_model->loadByCode('catalog_product', $arg_attribute);
        $attr_id = $attr->getAttributeId();

        $option['attribute_id'] = $attr_id;
        $option['value']['any_value'][0] = $arg_value;

        $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
        $setup->addAttributeOption($option);

        echo 'Option: ' . $label . ' created for Attribute: ' . $attribute_text . ' Please Re-run' . PHP_EOL;
        $_invalid_attributes_flag = true;

    } catch (Exception $e) {
        echo $e->getMessage . $attribute_text . ' ' . $label . PHP_EOL;
        exit;
    }

    return false;
}

/**
 * Returns an array of category IDs based on the product data passed in
 * Some extra category IDs can be tagged on to the end
 *
 * @param $simple
 * @return array
 */
function getCategoryIds($simple, $variant = null)
{
    global $config_json;

    $categories = [];
    $style_1 = $simple['style_1'];
    $style_2 = $simple['style_2'];
    $style_3 = $simple['style_3'];

    if ($style_1 === '' && !is_null($variant)) {
        $style_1 = $variant['style_1'];
    }
    if ($style_2 === '' && !is_null($variant)) {
        $style_2 = $variant['style_2'];
    }
    if ($style_3 === '' && !is_null($variant)) {
        $style_3 = $variant['style_3'];
    }

    $categories = [];
    if ($style_1 !== '') {
        $category = Mage::getResourceModel('catalog/category_collection')
            ->addFieldToFilter('name', $style_1)
            ->getFirstItem()// The parent category
        ;

        if (!is_null($category->getId())) {
            $categories[] = $category->getId();
        }
    }

    if ($style_2 !== '') {
        $category = Mage::getResourceModel('catalog/category_collection')
            ->addFieldToFilter('name', $style_2)
            ->getFirstItem()// The parent category
        ;

        if (!is_null($category->getId())) {
            $categories[] = $category->getId();
        }
    }

    if ($style_3 !== '') {
        $category = Mage::getResourceModel('catalog/category_collection')
            ->addFieldToFilter('name', $style_3)
            ->getFirstItem()// The parent category
        ;
        if (!is_null($category->getId())) {
            $categories[] = $category->getId();
        }
    }

    foreach ($config_json->additional_categories as $category_name) {
        $category = Mage::getResourceModel('catalog/category_collection')
            ->addFieldToFilter('name', $category_name)
            ->getFirstItem()// The parent category
        ;
        if (!is_null($category->getId())) {
            $categories[] = $category->getId();
        }
    }

    if ($config_json->add_brand_category) {
        $category = Mage::getResourceModel('catalog/category_collection')
            ->addFieldToFilter('name', $simple['brand'])
            ->getFirstItem()// The parent category
        ;
        if (!is_null($category->getId())) {
            $categories[] = $category->getId();
        }
    }

    return $categories;
}

/**
 * Generates an array of configurable variant data for the configurable
 * product to add a simple product variant
 *
 * @param $simple_id
 * @return array
 */
function generateConfigurableData($simple_id)
{
    global $configurable_options, $config_json;

    $data = [];
    $counter = 0;
    $product = Mage::getModel('catalog/product')->load($simple_id);

    foreach ($configurable_options as $attribute_text) {

        $label = '';
        $getter = '';

        foreach($config_json->attribute_set_options as $_attribute) {
            if ($_attribute->attribute->attribute_text === $attribute_text) {
                $getter = $_attribute->attribute->getter;
            }
        }
        $label = $product->{$getter}();


        $data['' . $counter] = [
            'label' => $product->getAttributeText($attribute_text),
            'attribute_id' => getAttributeId('' . $attribute_text),
            'value_index' => $label,
            'is_precent' => '0',
            'pricing_value' => '0'
        ];
        $counter++;
    }

    return $data;
}

/**
 * @param $value
 * @param $processor
 * @return mixed
 */
function numberConversionInchToMillimetre($value, $processor)
{
    switch($processor->operator):
        case 'lessthan':
            if (floatval($value) < floatval($processor->parameters)) {
                $value = floatval($value) * $processor->metrics;
            }
            break;
        default:
            break;
    endswitch;
    return $value;
}


/* ######################################################################################################################## */
/* ############################################# PROCESS THE CSV ########################################################## */
/* ######################################################################################################################## */

$handle = @fopen($input_file, "r");
if ($handle) :
    while (($buffer = fgetcsv($handle)) !== false) :
        if ($line === 0) {

            $fields = $buffer;
            $line++;
            continue;
        }

        if (($_should_batch && $counter >= $start_batch && $counter <= ($batch_limit))
            || !$_should_batch
        ){

            if (!$config_json->article ||  $config_json->article === $buffer[0] ) {

                $product = [];
                foreach ($buffer as $key => $val) {
                    $product[strtolower(str_replace(' ', '_', $fields[$key]))] = $val;
                }

                if ($product['product_type'] === 'Configurable') {
                    $tmp_name = $product['name'];
                    $tmp_brand = $product['brand'];
                    $tmp_color = $product['search_colour_1'];
                    $tmp_sku = strtolower(str_replace(' ', '-', $product['name']));
                    $style_1 = (strpos($product['style_1'], ' Wheels') === false && $product['style_1'] !== '') ? $product['style_1'] . ' Wheels' : $product['style_1'];
                    $style_2 = (strpos($product['style_2'], ' Wheels') === false && $product['style_2'] !== '') ? $product['style_2'] . ' Wheels' : $product['style_2'];
                    $style_3 = (strpos($product['style_3'], ' Wheels') === false && $product['style_3'] !== '') ? $product['style_3'] . ' Wheels' : $product['style_3'];
                } else {
                    if ($product['name'] === '') {
                        $product['name'] = $tmp_name;
                    }
                    $tmp_brand = $product['brand'];

                    if ($config_json->add_brand_to_name === true) {
                        $tmp_name = $tmp_brand . ' ' . $product['name'];
                    } else {
                        $tmp_name = $product['name'];
                    }

                    if ($product['colour']) {
                        $tmp_color = $product['colour'];
                    } else {
                        $tmp_color = $product['search_colour_1'];
                    }
                    $tmp_sku = strtolower(str_replace(' ', '-', $product['name']));
                    $style_1 = (strpos($product['style_1'], ' Wheels') === false && $product['style_1'] !== '') ? $product['style_1'] . ' Wheels' : $product['style_1'];
                    $style_2 = (strpos($product['style_2'], ' Wheels') === false && $product['style_2'] !== '') ? $product['style_2'] . ' Wheels' : $product['style_2'];
                    $style_3 = (strpos($product['style_3'], ' Wheels') === false && $product['style_3'] !== '') ? $product['style_3'] . ' Wheels' : $product['style_3'];

                    if (!isset($product['cost_price']) && isset($product['source_price'])) {
                        // We have a source price?
                        $product['cost_price'] = Mage::helper('attributehelper')->applyCurrencyConversion($product['source_price'], $product['cost_currency']);
                    }
                }

                $name = $tmp_name;
                $sku = strtolower(str_replace(' ', '-', $tmp_brand)) . '-' . $tmp_sku;

                if ($product['product_type'] === 'Simple') {
                    $sku = getSimpleSku($product, $sku);
                } elseif ($config_json->sku_required) {
                    $sku = getSimpleSku($product, $sku);
                }

                $product['sku'] = $sku;
                $product['name'] = $name;
                $product['colour'] = $tmp_color;

                if ($product['product_type'] === 'Configurable') {
                    $product['style_1'] = $style_1;
                    $product['style_2'] = $style_2;
                    $product['style_3'] = $style_3;
                    $product['name'] = $product['brand'] . ' ' . $product['name'];
                    $configurableProducts[] = $product;
                } else {
                    if ($product['name'] === '') {
                        $product['name'] = $tmp_name;
                    }
                    $product['brand'] = $tmp_brand;
                    $product['style_1'] = $style_1;
                    $product['style_2'] = $style_2;
                    $product['style_3'] = $style_3;

                    if (!isset($product['product_type'])) {
                        $product['product_type'] = 'Simple';
                    }

                    foreach ($_attribute_options as $_attribute) {
                        $attribute_text = $_attribute->attribute->attribute_text;
                        $attribute_setter = $_attribute->attribute->setter;
                        if ($_attribute->attribute->is_text) {
                            $text = ($product[$attribute_text] === 'NULL') ? '' : $product[$attribute_text];
                            $product[$attribute_text] = $text;
                        } else {
                            $product_value = $product[$attribute_text];
                            if (isset($_attribute->attribute->processors)) {
                                foreach($_attribute->attribute->processors as $processor) {
                                    $func = $processor->function;
                                    $product_value = $func($product_value, $processor);
                                }
                            }
                            $product[$attribute_text] = $product_value;
                        }
                    }

                    $simpleProducts[] = $product;
                }
            }
        }
        $counter++;

        if ($_should_batch && $counter > $batch_limit) {
            break;
        }

    endwhile;
endif;
fclose($handle);

// RUN THROUGH ALL SIMPLE PRODUCTS TO GET ALL OPTIONS AND VALUES
foreach ($_attribute_options as $_attribute) {

    if ($_attribute->attribute->is_text) {

    } elseif ($_attribute->attribute->is_multi) {
        // If is multi generate an array of all concatenated elements and test
        $concatenate = $_attribute->attribute->concatenate;

        $test_data = [];
        foreach ($simpleProducts as $simple) {
            foreach ($concatenate as $key => $value) {
                if ($_attribute->attribute->ignore_empty && $simple[$value] !== '') {
                    $test_data[$simple[$value]] = $simple[$value];
                }
            }
        }

        foreach ($test_data as $key => $data) {
            getAttributeValueIdFromCode($_attribute->attribute->attribute_text, $data);
        }
    } else {

        $test_data = [];
        foreach ($simpleProducts as $simple) {
            $test_data[$simple[$_attribute->attribute->attribute_text]] = $simple[$_attribute->attribute->attribute_text];
        }

        foreach ($test_data as $data) {
            getAttributeValueIdFromCode($_attribute->attribute->attribute_text, $data);
        }
    }
}

if ($_invalid_attributes_flag) {
    exit('Re-run');
}

// Test we have no skus that are identical
// Necessary to sanitize the simple data and avoid
// duplications arising from our SKU generation
if ($config_json->should_filter) {
    $duplicates = [];
    foreach ($simpleProducts as $key => $val) {
        foreach ($simpleProducts as $key2 => $val2) {
            if ($val['sku'] === $val2['sku'] && $key !== $key2 && $key2 > $key && !in_array($key2, $duplicates)) {
                $duplicates[] = $key2;
            }
        }
    }

    $tmp = [];
    foreach ($simpleProducts as $key => $val) {
        if (!in_array($key, $duplicates)) {
            $tmp[$key] = $val;
        }
    }
    $simpleProducts = $tmp;

// RE-RUN TO MAKE SURE
    $duplicates = [];
// Test we have no skus that are identical
    foreach ($simpleProducts as $key => $val) {
        foreach ($simpleProducts as $key2 => $val2) {
            if ($val['sku'] === $val2['sku'] && $key !== $key2 && $key2 > $key && !in_array($key2, $duplicates)) {
                $duplicates[] = $key2;
            }
        }
    }

    $tmp = [];
    foreach ($simpleProducts as $key => $val) {
        if (!in_array($key, $duplicates)) {
            $tmp[$key] = $val;
        }
    }
    $simpleProducts = $tmp;
}

/*********************************/
// We should now have clean data
/*********************************/

foreach ($simpleProducts as $simple):

//    var_dump($simple);
//    exit;
    $categories = getCategoryIds($simple);

    $product_id = Mage::getModel('catalog/product')->getIdBySku($simple['sku']);

    if (!$product_id) {

        try {
            echo 'Creating product :: ' . getName($simple) . PHP_EOL;

            $dat = Mage::getModel('catalog/product');
            $dat
                ->setWebsiteIds($config_json->website_ids)
                ->setAttributeSetId($attribute_set_id)
                ->setTypeId(strtolower($simple['product_type']))
                ->setCreatedAt(strtotime(now()))
                ->setSku($simple['sku'])
                ->setName(getName($simple))
                ->setWeight(($simple['weight'] === '' || !isset($simple['weight'])) ? 1 : $simple['weight'])
                ->setStatus(1)
                ->setTaxClassId(2)
                ->setVisibility($config_json->simple_visible)
                ->setBrand($simple['brand'])
                ->setPrice(floatval(str_replace(['£','$','€'],'', $simple['cost_price'])))
                ->setDescription((isset($simple['description']) && $simple['description'] !== '') ? $simple['description'] : getName($simple) . ' awaiting content')
                ->setShortDescription((isset($simple['short_description']) && $simple['short_description'] !== '') ? $simple['short_description'] : getName($simple))
                ->setStockData(array(
                        'use_config_manage_stock' => 0,     //'Use config settings' checkbox
                        'manage_stock' => 1,                //manage stock
                        'min_sale_qty' => 0,                //Minimum Qty Allowed in Shopping Cart
                        'is_in_stock' => 1,                 //Stock Availability
                        'qty' => $config_json->stock_qty    //qty
                    )
                )
                ->setCategoryIds($categories);

            // THESE ARE CONFIGURABLE VARIANTS UNIQUE PER ATTRIBUTE SET
            foreach ($_attribute_options as $_attribute) {
                $attribute_text = $_attribute->attribute->attribute_text;
                $attribute_setter = $_attribute->attribute->setter;
                if ($_attribute->attribute->is_text) {
                    $text = ($simple[$attribute_text] === 'NULL') ? '' : $simple[$attribute_text];
                    $dat->{$attribute_setter}(($text));
                } elseif($_attribute->attribute->is_multi) {
                    
                    // generate save function for multi
                    $concatenate = $_attribute->attribute->concatenate;
                    $tmp_concatenate = [];
                    foreach($concatenate as $conc) {
                        if ($_attribute->attribute->ignore_empty && $simple[$conc] !== '') {
                            $tmp_concatenate[
                            ] = getAttributeValueIdFromCode($_attribute->attribute->attribute_text, $simple[$conc]);
                        }
                    }
                    $dat->{$attribute_setter}(implode(',', $tmp_concatenate));

                } else {
                    $product_value = $simple[$attribute_text];
                    $dat->{$attribute_setter}(getAttributeValueIdFromCode($attribute_text, $product_value));
                }
            }
            try {
                $dat = $dat->save();
            } catch(Exception $e) {
                echo $e->getMessage() . PHP_EOL;
                exit;
            }
            $variants[$simple['name']][] = intval($dat->getId());

        } catch (Exception $e) {
            Mage::log($e->getMessage());
            echo $e->getMessage() . PHP_EOL;
        }
    } else {
        echo '##### ' . $product_id . ' item exists ' . getName($simple) . ' ' . $simple['sku'] . PHP_EOL;
        Mage::log($product_id . ' item exists ' . getName($simple) . ' ' . $simple['sku'] , null, 'importer.log');
        if ($config_json->simple_product_bypass_save === false) {
            $dat = Mage::getModel('catalog/product')->load($product_id);
            $categories = getCategoryIds($simple);
            $dat->setCategoryIds($categories)
                ->setPrice(floatval(str_replace(['£','$','€'],'', $simple['cost_price'])))
                ->setDescription((isset($simple['description']) && $simple['description'] !== '') ? $simple['description'] : getName($simple) . ' awaiting content')
                ->setShortDescription((isset($simple['short_description']) && $simple['short_description'] !== '') ? $simple['short_description'] : getName($simple));
            foreach ($_attribute_options as $_attribute) {
                $attribute_text = $_attribute->attribute->attribute_text;
                $attribute_setter = $_attribute->attribute->setter;
                if ($_attribute->attribute->is_text) {
                    $text = ($simple[$attribute_text] === 'NULL') ? '' : $simple[$attribute_text];
                    $dat->{$attribute_setter}(($text));
                } elseif($_attribute->attribute->is_multi) {
                    // generate save function for multi
                    $concatenate = $_attribute->attribute->concatenate;
                    $tmp_concatenate = [];
                    foreach($concatenate as $conc) {
                        if ($_attribute->attribute->ignore_empty && $simple[$conc] !== '') {
                            $tmp_concatenate[
                            ] = getAttributeValueIdFromCode($_attribute->attribute->attribute_text, $simple[$conc]);
                        }
                    }
                    $dat->{$attribute_setter}(implode(',', $tmp_concatenate));

                } else {
                    $product_value = $simple[$attribute_text];

                    $dat->{$attribute_setter}(getAttributeValueIdFromCode($attribute_text, $product_value));
                }
            }
            $dat->save();

        }
        $variants[$simple['name']][] = intval($product_id);
    }

endforeach;


// Run the script for Configurables
foreach ($configurableProducts as $item) :
    $configProduct = Mage::getModel('catalog/product');

    $categories = getCategoryIds($item, $variants[$item['name']][0]);

    try {
        $sku = $item['sku'];
//        echo $item['name'] . PHP_EOL;
//        exit($sku);
        $product_id = Mage::getModel('catalog/product')->getIdBySku($item['sku']);
        if (!$product_id) {
            echo 'Create Configurable product :: ' . $item['name'] . PHP_EOL;

            $lowest_price = 10000;
            foreach ($variants[$item['name']] as $variant) {
                $_child = Mage::getModel('catalog/product')->load($variant);
                if ($_child->getFinalPrice() < $lowest_price) {
                    $lowest_price = $_child->getFinalPrice();
                }
            }

            $configProduct = Mage::getModel('catalog/product');
            $configProduct
                ->setWebsiteIds($config_json->website_ids)
                ->setAttributeSetId($attribute_set_id)              //ID of a attribute set named 'default'
                ->setTypeId('configurable')                         //product type
                ->setCreatedAt(strtotime('now'))                    //product creation time
                ->setSku($sku)                                      //SKU
                ->setName($item['name'])                            //product name
                ->setWeight(1)
                ->setStatus(1)                                      //product status (1 - enabled, 2 - disabled)
                ->setTaxClassId(2)                                  //tax class (0 - none, 1 - default, 2 - taxable, 4 - shipping)
                ->setVisibility($config_json->configurable_visible) //catalog and search visibility
                ->setBrand(getAttributeValueIdFromCode('brand', $item['brand']))//manufacturer id

                // If Config item does not have colour data
                ->setColour(getAttributeValueIdFromCode('colour', ($item['colour'] === '') ? $variants[$item['name']][0]['colour'] : $item['colour']))

                ->setPrice($lowest_price)
                ->setDescription($item['description'])
                ->setShortDescription($item['name'])
                ->setStockData(array(
                        'use_config_manage_stock' => 0, //'Use config settings' checkbox
                        'manage_stock' => 1, //manage stock
                        'is_in_stock' => 1, //Stock Availability
                    )
                )
                ->setCategoryIds($categories) //assign product to categories
            ;

            $configProduct->getTypeInstance()->setUsedProductAttributeIds($configurable_option_ids);
            $configurableAttributesData = $configProduct->getTypeInstance()->getConfigurableAttributesAsArray();

            $configProduct->setCanSaveConfigurableAttributes(true);
            $configProduct->setConfigurableAttributesData($configurableAttributesData);

            $configurableProductsData = array();

            // Get all variant items and add to config product data
            foreach ($variants[$item['name']] as $simple_id) {
                $configurableProductsData[$simple_id] = generateConfigurableData($simple_id);
            }

            var_dump(count($configurableProductsData));

            $configProduct->setConfigurableProductsData($configurableProductsData);
            $configProduct->setCustomLayoutUpdate('<reference name="product.info">
<action method="setTemplate"><template>catalog/product/alloy_view.phtml</template></action>
</reference>
<reference name="product.info.options.configurable">
<action method="setTemplate"><template>catalog/product/view/type/options/alloy_configurable.phtml</template></action>
</reference>
<reference name="product.info.addtocart">
<action method="setTemplate"><template>catalog/product/view/alloy_addtocart.phtml</template></action>
</reference>');

            $configProduct->save();

        } else {
            echo '##### Configurable product exists :: ' . $item['name'] . PHP_EOL;
            $lowest_price = 10000;
            foreach ($variants[$item['name']] as $variant) {
                $_child = Mage::getModel('catalog/product')->load($variant);
                if ($_child->getFinalPrice() < $lowest_price) {
                    $lowest_price = $_child->getFinalPrice();
                }
            }

            // Handle if configurable has no colour dataa
            $colour = ($item['colour'] === '') ? Mage::getModel('catalog/product')->load($variants[$item['name']][0])->getColour() : $item['colour'];
            $configProductM = Mage::getModel('catalog/product');
            $configProduct = $configProductM->load($product_id);
            $categories = getCategoryIds($item, $variants[$item['name']][0]);

            $configProduct->setCategoryIds($categories);
            $configProduct->setPrice($lowest_price);
            $configProduct->setColour(getAttributeValueIdFromCode('colour', $colour));

            // Get all variant items and add to config product data
            foreach ($variants[$item['name']] as $simple_id) {
                $configurableProductsData[$simple_id] = generateConfigurableData($simple_id);
            }
            $configProduct->setConfigurableProductsData($configurableProductsData);

            $configProduct->setCustomLayoutUpdate('<reference name="product.info">
<action method="setTemplate"><template>catalog/product/alloy_view.phtml</template></action>
</reference>
<reference name="product.info.options.configurable">
<action method="setTemplate"><template>catalog/product/view/type/options/alloy_configurable.phtml</template></action>
</reference>
<reference name="product.info.addtocart">
<action method="setTemplate"><template>catalog/product/view/alloy_addtocart.phtml</template></action>
</reference>');
            $configProduct->save();
        }

        echo 'success' . PHP_EOL;
    } catch (Exception $e) {
        Mage::log($e->getMessage());
        echo 'EXCEPTION: ' . $e->getMessage();
    }

endforeach;

echo 'COMPLETED' . PHP_EOL;
exit;