<?php
/* Created by Vitalii Puhach */

class CMSClassGlassesParserRemeyewear extends CMSClassGlassesParser {
    private $db;

    const URL_ROOT = 'http://www.remeyewear.com';
    const URL_RAPIDORDER = 'http://www.remeyewear.com/account/rapidorder.aspx';

    // хранит массив всех брендов провайдера, ключами которых является код бренда
    private $_coded_brands;


    /**
     * Возвращает id провайдера
     * @return int
     */
    public function getProviderId() {
        return CMSLogicProvider::REMEYEWEAR;
    }

    /**
     * Для входа на сайт
     */
    public function doLogin()
    {
        $http = $this->getHttp();

        // вытягиваем из скрытых полей страници опции для логина
        $http->doGet(self::URL_ROOT);
        $content = $this->getHttp()->getContents();
        $dom = str_get_html($content);

        $post = array (
            'ctl00$ucHeader$ucAccountNav$hdnNavPageName' => '',
            'txbQuickSearch' => '',
            'ctl00$Login1$txbAcctUserName' => $this->getUsername(),
            'ctl00$Login1$txbPassword' => $this->getPassword(),
            'ctl00$Login1$btnLogin' => 'Log In',
        );

        $hidden = $dom->find('.aspNetHidden input');

        foreach($hidden as $hide){
           $name = $hide->attr['name'];
           $val = $hide->attr['value'];
           $post[$name] = $val;
        }

        $http->doPost(self::URL_ROOT, $post);
    }

    /**
     * Проверка успешного логина
     * @param $contents string
     * @return bool
     */
    public function isLoggedIn($contents) {
        return strpos($contents, 'title="Logout"') !== false;
    }

    /**
     * Синхронизация брендов на сайте из существующими в системе
     */
    public function doSyncBrands() {
        $http = $this->getHttp();
        $brands = array();
        $option_value = '';

        if(!$http->doGet(self::URL_RAPIDORDER)) {
            throw new CMSException();
        }

        $content = $http->getContents();
        $dom = str_get_html($content);

        $brands_options_dom = $dom->find('#MainContent_ddlCollectionGroup option');
        // удаляем первую так как она просто информирует
        unset($brands_options_dom[0]);

        foreach($brands_options_dom as $brand_option) {
            $option_value = $brand_option->value;

            $option_value_explode = explode("|", $option_value);
            $brand_value = trim($option_value_explode[0]);

            $brands[$brand_value] = array(
                'name' => $brand_value,
                'code' => $brand_value,
            );
        }

        if(!$brands) {
            throw new CMSException();
        }

        $myBrands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        foreach($myBrands as $m) {
            if ($m instanceof CMSTableBrand) {
                $coded[$m->getCode()] = $m;
            }
        }

        $this->_coded_brands = $coded;

        foreach($brands as $code => $info) {
            $name = $info['name'];

            if (!isset($coded[$code])) {
                echo "--Create brand - {$name}, code - {$code}\n";
                CMSLogicBrand::getInstance()->create($this->getProvider(), $name, $code, '');
            } else {
                echo "--Brand - {$name}, code - {$code} already isset\n";
            }
        }
    }

    /**
     * Возвращает из http ответа обновленные свойства для следующего запроса
     * @param $content string
     * @return array
     */
    private function _get_options_from_response($content) {
        preg_match("/__VIEWSTATE\|(.*?)\|8\|hiddenField\|__VIEWSTATEGENERATOR\|(.*?)\|.*\|hiddenField\|__EVENTVALIDATION\|(.*?)\|/", $content, $matches);

        $__VIEWSTATE = $matches[1];
        $__VIEWSTATEGENERATOR = $matches[2];
        $__EVENTVALIDATION = $matches[3];

        return array(
            '__VIEWSTATE' => $__VIEWSTATE,
            '__VIEWSTATEGENERATOR' => $__VIEWSTATEGENERATOR,
            '__EVENTVALIDATION' => $__EVENTVALIDATION,
        );
    }

    /**
     * Подготовка данных и нажатие на каждую опцию селекта с брендами
     */
    public function doSyncItems() {
        $already_reset_models_brand = '';
        $http = $this->getHttp();
        // для хранения базовых(скрытых на странице ключей)
        $post_hidden = array();

        if (!$http->doGet(self::URL_RAPIDORDER)) {
            throw new CMSException();
        }

        $content = $this->getHttp()->getContents();
        $dom = str_get_html($content);

        $hidden = $dom->find('.aspNetHidden input');

        foreach($hidden as $hide){
            $name = $hide->attr['name'];
            $val = $hide->attr['value'];
            $post_hidden[$name] = $val;
        }

        $brands_options_dom = $dom->find('#MainContent_ddlCollectionGroup option');
        // удаляем первую так как она просто информирует
        unset($brands_options_dom[0]);

        foreach($brands_options_dom as $brand_option) {
            echo "\n##########################################################\n";
            echo "#-- Sync select option - {$brand_option->plaintext}\n";
            echo "##########################################################\n\n";
            $option_value = $brand_option->value;

            $option_value_explode = explode("|", $option_value);
            $brand_value = trim($option_value_explode[0]);
            $type_value = trim($option_value_explode[2]);

            if(isset($this->_coded_brands[$brand_value])) {
                $brand = $this->_coded_brands[$brand_value];
            } else {
                throw new CMSException("Wrong brand???");
            }

            if ($brand instanceof CMSTableBrand) {
                if ($brand->getValid()) {
                    echo get_class($this), ': syncing items of brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
                } else {
                    echo get_class($this), ': SKIP! syncing items of Disabled brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
                    continue;
                }
            } else {
                throw new CMSException("brand must be instance of CMSTableBrand!!!");
            }

            // так как в первом селекте один бренд под разные виды очков (для детей, взрослых)
            // то по названию бренда будем сбрасывать флаги только раз иначе валидными и в стоке останутся лишь модели последнего
            // пришедшего бренда
            if($already_reset_models_brand !== $brand->getTitle()) {
                // Сбрасываем is_valid для моделей бренда - флаг наличия модели у провайдера
                echo "Reset model by brand - {$brand->getTitle()}\n";
                $this->resetModelByBrand($brand);
                // Сбрасываем сток для бренда
                $this->resetStockByBrand($brand);
                $already_reset_models_brand = $brand->getTitle();
            }

            $select_brand_post = array(
                'ctl00$ScriptManager1' =>'ctl00$MainContent$uplRapidOrder|ctl00$MainContent$ddlCollectionGroup',
                'ctl00$ucHeader$ucAccountNav$hdnNavPageName' =>'',
                'txbQuickSearch' => 'order now',
                'ctl00$MainContent$ddlCollectionGroup' => $option_value,
                'ctl00$MainContent$ddlStyles' => '',
                'ctl00$Login1$txbAcctUserName' => '30358000',
                'ctl00$Login1$txbPassword' => 'gg1970gg',
                '__ASYNCPOST' => 'true',
            );
            $brand_post = array_merge($post_hidden, $select_brand_post);

            $this->_clickBrand($brand_post, $post_hidden, $select_brand_post, $brand, $type_value);
        }

        $dom->clear(); // подчищаем за собой
        unset($dom);
    }

    /**
     * После нажатина по бренду получает все модели и делает по ним клик
     * @param $brand_post array
     * @param $post_hidden array
     * @param $select_brand_post array
     * @param CMSTableBrand $brand
     * @param $type_value string
     */
    private function _clickBrand($brand_post, $post_hidden, $select_brand_post, CMSTableBrand $brand, $type_value) {
        $http = $this->getHttp();
        // первый select - получаем модели по бренду
        $http->doPost(self::URL_RAPIDORDER, $brand_post);
        $content = $http->getContents();
        $dom = str_get_html($content);

        $hidden_options = $this->_get_options_from_response($content);

        $hidden_options = array_merge($post_hidden, $hidden_options);
        $hidden_options = array_merge($hidden_options, $select_brand_post);

        $options_model_dom = $dom->find('#MainContent_ddlStyles option');
        // удаляем первую так как она просто информирует
        unset($options_model_dom[0]);

        foreach($options_model_dom as $option_model) {
            $model_name = trim($option_model->plaintext);
            echo "================================================\n";
            echo "----Model - {$model_name}.\n";

            $option_model_code = $option_model->value;
            $model_post = array(
                'ctl00$MainContent$ddlStyles' => $option_model_code,
            );

            $model_post = array_merge($hidden_options , $model_post);

            $this->_clickModel($model_post, $model_name, $brand, $type_value);

        }
        $dom->clear(); // подчищаем за собой
        unset($dom);
    }

    /**
     * После нажатия на модель получает вариации, собирает информацию о свойствах, и добавляет в корзину и удаляет из нее
     * @param $model_post array
     * @param $model_name string
     * @param CMSTableBrand $brand
     * @param $type_value string
     */
    private function _clickModel($model_post, $model_name, CMSTableBrand $brand, $type_value) {
        $http = $this->getHttp();

        // выбираем модель и получаем вариации
        $http->doPost(self::URL_RAPIDORDER, $model_post);
        $content = $http->getContents();
        $dom = str_get_html($content);

        $hidden_options = $this->_get_options_from_response($content);
        $options_color_dom = $dom->find('#MainContent_ddlConfigurations option');
        // удаляем первую так как она просто информирует
        unset($options_color_dom[0]);
        foreach($options_color_dom as $option_color) {
            $item = array();

            $item['name'] = $model_name;

            $option_color_text = trim($option_color->plaintext);
            $upc = trim($option_color->value);

            preg_match('/(.*)[ ]*\((.*)\)[ ]*(.*)[ ]*\$(.*)/', $option_color_text, $matches);

            $matches[1] = trim($matches[1]);
            $matches[2] = trim($matches[2]);

            $item['color_title'] =!empty($matches[1]) ? $matches[1] : $matches[2];
            $item['color_code'] = !empty($matches[2]) ? $matches[2] : $matches[1];
            $item['sizes'] = trim($matches[3]);
            $item['price'] = trim($matches[4]);
            $item['upc'] = trim($upc);

            // формируем пост при выборе опции
            $color_post = array(
                'ctl00$ScriptManager1' => 'ctl00$MainContent$uplRapidOrder|ctl00$MainContent$ddlConfigurations',
                'ctl00$MainContent$ddlConfigurations' => $upc,
                '__EVENTTARGET' => 'ctl00$MainContent$ddlConfigurations',
            );

            $model_post = array_merge($model_post, $hidden_options);
            $color_post = array_merge($model_post, $color_post);

            $content = $this->_addToCart($item ,$color_post, $model_post, $brand, $type_value);

            $dom = str_get_html($content);
            $cart_id_dom = $dom->find('#MainContent_ucCart_rptCart_hfCartItemID_0');

            $cart_id = '';
            if(count($cart_id_dom)) {
                $cart_id = $cart_id_dom[0]->value;
            }

            $hidden_options = $this->_get_options_from_response($content);
            $model_post = array_merge($model_post, $hidden_options);
            $this->deleteFromCart($upc, $cart_id, $model_post);

        }
        $dom->clear(); // подчищаем за собой
        unset($dom);
    }

    /**
     * Нажимает на вариацию, формирует запрос и добавляет в корзину.
     * Возвращает ответ полученный после добавления в корзину для формирования запроса с обновленными данными
     * на удаление из корзины
     * @param $item array
     * @param $color_post array
     * @param $model_post array
     * @param CMSTableBrand $brand
     * @param $type_value string
     * @return string
     */
    private function _addToCart($item, $color_post, $model_post, CMSTableBrand $brand, $type_value) {
        $http = $this->getHttp();
        // выбираем опцию
        $http->doPost(self::URL_RAPIDORDER, $color_post);
        $content = $http->getContents();

        $hidden_options = $this->_get_options_from_response($content);

        // формируем пост при добавлении в корзину
        $cart_post = array(
            'ctl00$ScriptManager1' =>'ctl00$MainContent$uplRapidOrder|ctl00$MainContent$btnAdd',
            'ctl00$MainContent$btnAdd' => 'Add to Cart',
        );
        $model_post = array_merge($model_post, $hidden_options);
        $cart_post = array_merge($model_post, $cart_post);

        // добавляем в корзину
        $http->doPost(self::URL_RAPIDORDER, $cart_post);
        $content = $http->getContents();

        // здесь будем забирать цену, сток и формировать финальные данные
        $this->parsePageItems($content, $brand, $type_value, $item);

        return $content;
    }

    /**
     * Для удаления уже добавленого товара из корзины
     * @param $upc string
     * @param $cart_id int
     * @param $model_post array
     */
    private function deleteFromCart($upc, $cart_id, $model_post) {
        $http = $this->getHttp();
        echo "\n--------remove from cart, upc {$upc}\n";
        $delete_post = array(
            'ctl00$ScriptManager1' => 'cctl00$MainContent$ucCart$uplCart|ctl00$MainContent$ucCart$rptCart$ctl00$btnDelete',
            'ctl00$MainContent$ucCart$rptCart$ctl00$tbQuantity' => 1,
            'ctl00$MainContent$ucCart$rptCart$ctl00$hfCartItemID' => $cart_id,
            'ctl00$MainContent$ucCart$rptCart$ctl00$hfUPC' => $upc,
            'ctl00$MainContent$ucCart$rptCart$ctl00$tbPatientTray' => '',
            'ctl00$MainContent$ucCart$rptCart$ctl00$btnDelete' => 'remove frame',
        );

        $delete_post = array_merge($model_post, $delete_post);

        $http->doPost(self::URL_RAPIDORDER, $delete_post);
    }

    /**
     * Достает сток и изображение вариации из корзины, формирует обьект и отправляет на синхронизацию
     * @param $content
     * @param CMSTableBrand $brand
     * @param $type_value
     * @param $item
     */
    private function parsePageItems($content, CMSTableBrand $brand, $type_value, $item) {
        $stock = '';
        $image = '';
        $external_id = '';

        $dom = str_get_html($content);

        // достаем сток
        $option_stock_dom = $dom->find('#MainContent_ucCart_rptCart_lblAvailMarker_0');
        if(count($option_stock_dom)) {
            $stock_str = trim($option_stock_dom[0]->plaintext);
            $stock = trim($stock_str) === "IN-STOCK" ? 1 : 0;
        }

        // достаем изображение
        $option_img_dom = $dom->find('#MainContent_ucCart_rptCart_imgFrame_0');
        if(count($option_img_dom)) {
            $image = preg_replace("/&amp;w=(.*)/", '&amp;w=1250', $option_img_dom[0]->src);
            $image = self::URL_ROOT . $image;
        }
        $image = html_entity_decode($image);

        // разбираемся с размерами
        $sizes_arr = explode('-', $item['sizes']);
        $size_1 = isset($sizes_arr[0]) ? $sizes_arr[0] : 0;
        $size_2 = isset($sizes_arr[1]) ? $sizes_arr[1] : 1;
        $size_3 = isset($sizes_arr[2]) ? $sizes_arr[2] : 2;

        // определяем тип очков
        if($type_value === "Sun") {
            $typeItem = CMSLogicGlassesItemType::getInstance()->getSun();
        } else {
            $typeItem = CMSLogicGlassesItemType::getInstance()->getEye();
        }

        // Формируем переменную которая будет уникальным идентификатором каждой модели
        // Собственно по ней вариации к модели и попадут
        $external_id = $item['name']." prov ".$this->getProviderId();

        echo "\n";
        echo "--------brand    - {$brand->getTitle()}\n";
        echo "--------model_name    - {$item['name']}\n";
        echo "--------color_title   - {$item['color_title']}\n";
        echo "--------external_id   - {$external_id}\n";
        echo "--------color_code    - {$item['color_code']}\n";
        echo "--------sizes         - {$item['sizes']}\n";
        echo "--------image         - {$image}\n";
        echo "--------price         - {$item['price']}\n";
        echo "--------type          - {$type_value}\n";
        echo "--------stock         - {$stock}\n";
        echo "--------upc           - {$item['upc']}\n\n";

        // создаем обьект модели и синхронизируем
        $sitem = new CMSClassGlassesParserItem();
        $sitem->setBrand($brand);
        $sitem->setExternalId($external_id);
        $sitem->setType($typeItem);
        $sitem->setTitle($item['name']);
        $sitem->setColor($item['color_title']);
        $sitem->setColorCode($item['color_code']);
        $sitem->setStockCount($stock);
        $sitem->setPrice($item['price']);
        $sitem->setImg($image);
        $sitem->setSize($size_1);
        $sitem->setSize2($size_2);
        $sitem->setSize3($size_3);
        $sitem->setIsValid(1);
        $sitem->setUpc($item['upc']);
        $sitem->sync();
    }
}
?>
