<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Electrozon parser script (runnig with cron)</title>
    <style>
        .error, .exception {
            color: red;
        }
    </style>
</head>
<body>

<?php
/**
 * Created by PhpStorm.
 * User: matt
 * Date: 04.12.2018
 * Time: 14:20
 */

CONST ELECTROZON_PATH = 'https://electrozon.ru/files/market_filial_new.yml';

// подключение конфига магазина
//require('../../../config.php');
require $_SERVER['DOCUMENT_ROOT'] . '/admin/config.php';

// Функция транслита имен категорий и товаров для SEO
function translit($s)
{
    $s = (string)$s; // преобразуем в строковое значение
    $s = strip_tags($s); // убираем HTML-теги
    $s = str_replace(array("\n", "\r"), " ", $s); // убираем перевод каретки
    $s = preg_replace("/\s+/", ' ', $s); // удаляем повторяющие пробелы
    $s = trim($s); // убираем пробелы в начале и конце строки
    $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); // переводим строку в нижний регистр (иногда надо задать локаль)
    $s = strtr($s, array('а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ы' => 'y', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ъ' => '', 'ь' => ''));
    $s = preg_replace("/[^0-9a-z-_ ]/i", "", $s); // очищаем строку от недопустимых символов
    $s = str_replace(" ", "-", $s); // заменяем пробелы знаком минус
    return $s; // возвращаем результат
}

// Переменная для рекурсивной функции составления пути категории
$pathy = array();

// Рекурсивная функция для составления пути категории
function getpath($id)
{
    // найти элемент с id
    // внести его в массив
    // если у него есть родитель, повторить
    // если нет, остановить
    global $cat_array, $pathy;
    $pathy[] = $id;
    if ($cat_array[$id]) {
        if ($cat_array[$id] > 0) {
            getpath($cat_array[$id]);
        }
    }
}

try {

    //Создаем файл лога с именем temp
    $logfile = fopen("temp", 'a+');

    // TODO Как вариант, проверка когда последний раз был парсер
    // если менее чем параметр частоты, то остановить скрипт
    // проверка по дате лога
    // в целом, запуск скрипта осуществляется по cron

    // загрузка файла из electrozon.ru
    if (!copy(ELECTROZON_PATH, 'market_filial_new.yml.xml'))
        throw new Exception('File ' . ELECTROZON_PATH . ' with goods from electozon.ru not availiable.');

    // Загружаем DOM из файла
    $doc = new DOMDocument();
    $doc->load('market_filial_new.yml.xml');

    // Выбираем все категории товаров
    $cats = $doc->getElementsByTagName('category');
    // Выбираем все товары
    $prods = $doc->getElementsByTagName('offer');

    // Составляем массив категорий, с id родителя, для составления пути категории (нужно для БД opencart)
    $cat_array = array();
    foreach ($cats as $cat) {
        $parent_id = $cat->getAttribute('parentId');
        if (!$parent_id) $parent_id = 0;
        $cat_array[$cat->getAttribute('id')] = $parent_id;
    }

    // подключение к БД магазина
    $mysqli = new mysqli("localhost", DB_USERNAME, DB_PASSWORD, DB_DATABASE);
    if ($mysqli->connect_errno) {
        throw new Exception('Cannot connect to MySQL DB: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
    }
    echo $mysqli->host_info . "<br/>\n";

    // Получаем установленный язык для магазина (из настроек) и таблицы
    $q = "SELECT language_id FROM " . DB_PREFIX . "language WHERE `code`=(SELECT `value` FROM " . DB_PREFIX . "setting WHERE `key`='config_language')";
    if (!$result = $mysqli->query($q)) {
        throw new Exception('Problem in opencart installation settings.');
    }
    $base_lang = 1;
    $base_lang = (Int)$result->fetch_object()->language_id;

    // Получаем базовую наценку для товаров
    $q = 'SELECT `value` FROM ' . DB_PREFIX . 'setting WHERE `key`="dashboard_electroparser_markup"';
    if (!$result = $mysqli->query($q)) {
        throw new Exception('Markup price not found or not set in opencart shop. Install extension or setup this setting.');
    }
    $base_markup = (Int)$result->fetch_object()->value;

    // Получаем таблицу с наценками по категориям (если наценки нет, то в массиве будет пустое значение
    $q = 'SELECT c.category_id, c.parent_id, d.name, m.markup FROM `' . DB_PREFIX .
        'category` c JOIN `' . DB_PREFIX . 'category_description` d ON c.category_id = d.category_id LEFT JOIN `' . DB_PREFIX .
        'category_markup` m ON (c.category_id = m.category_id)';

    $category_markup = array();
    if (!$result = $mysqli->query($q)) {
        // Такой таблицы (категорий) нет
        throw new Exception('Categories table not found');
    } else {
        if ($result->num_rows > 0) {
            while ($obj = $result->fetch_assoc()) {
                $category_markup[] = $obj;
            }
        } else {
            // Нет категорий вообще, поэтому будет установлена базовая наценка для всех
            // важно - наценки не могут существовать без категорий в БД!
            fwrite($logfile, "No categories in DB\n");
            echo "No categories in DB<br>";
        }
    }


    // Проходим по всем категориям, которые получили из файла, сверяем с теми категориями, которые есть в БД
    // Помечаем те, которые только появились в логе как new
    // В конце помечаем те, которые отсутствуют как deleted
    // Если изменено название - помечаем это как changed
    // Остальные - hold

    // Очищаем дополнительные таблицы с описаниями и путями TRUNCATE TABLE Table1
    $q = "TRUNCATE TABLE " . DB_PREFIX . "category_description";
    if (!$mysqli->query($q)) {
        fwrite($logfile, "Cannot truncate category table: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
        echo "Cannot truncate category table: (" . $mysqli->errno . ") " . $mysqli->error;
    }
    $q = "TRUNCATE TABLE " . DB_PREFIX . "category_path";
    if (!$mysqli->query($q)) {
        fwrite($logfile, "Cannot truncate category table: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
        echo "Cannot truncate category table: (" . $mysqli->errno . ") " . $mysqli->error;
    }
    $q = "TRUNCATE TABLE " . DB_PREFIX . "category_to_store";
    if (!$mysqli->query($q)) {
        fwrite($logfile, "Cannot truncate category table: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
        echo "Cannot truncate category table: (" . $mysqli->errno . ") " . $mysqli->error;
    }

    foreach ($cats as $cat) {
        // Название категории
        $name = $cat->nodeValue;
        // Идентификатор
        $category_id = $cat->getAttribute('id');
        // Идентификатор родителя
        $parent_id = 0;
        $parent_id = $cat->getAttribute('parentId');
        // Помечаем, если родителя нет или = 0 то это верхняя категория top = 0
        if (!$parent_id || ($parent_id == 0)) {
            $parent_id = 0;
            $top = 1;
        } else {
            $top = 0;
        }
        // Создаем транслит
        // TODO его надо будет записывать в таблицу re_seo_url!
        $link = translit($name);
        // Пока запишем в описание категории
        $desc = $link;
        // Ищем такую категорию в существующей таблице
        // Проверяем, не изменялось ли название
        $hold = array_search($category_id, array_column($category_markup, 'category_id'));
        if ($hold === FALSE) $state = "new"; else {
            if ($category_markup[$hold]['name'] === $name) $state = "hold"; else $state = "changed";
            // также надо проверить родителя, вдруг его перенесли в другую категорию!
            if ($category_markup[$hold]['parent_id'] <> $parent_id) $state = "changed";
        }
        $category_markup[$hold]['state'] = $state;

        // Вставляем в главную таблицу
        // Column - не имеет значения, он задает сколько столбцов при выводе товара
        // Sort_order - порядок сортировки
        // Status - активен (1)
        // TODO даты
        // Дата добавление - берем текущую (можно оставлять старую, если статус hold
        // Дата изменения - ставим текущую, если изменяем, для тех! у кого state - changed

        if ($state == "new") {
            $q = "INSERT INTO " . DB_PREFIX . "category(category_id, parent_id, top, `column`, sort_order, status, date_added, date_modified) VALUES " .
                "($category_id, $parent_id, $top, 0, 0, 1, now(), now())";
        };
        if ($state == "changed") {
            $q = "UPDATE " . DB_PREFIX . "category SET parent_id=$parent_id, top=$top, date_modified=now()  WHERE category_id = $category_id";
        }
        if ($state <> "hold") {
            if (!$mysqli->query($q)) {
                fwrite($logfile, "Cannot write category: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
                echo "Cannot write: (" . $mysqli->errno . ") " . $mysqli->error;
            }
        }

        // Вставляем в таблицу описаний
        // language_id - берем из настроек $base_lang
        // name - имя
        // description - пустой, никаких описаний не передается, его не изменять! если не мняется название
        // meta_title - записывать туда название, это для заголовка страницы
        // Записывать только если state - new!
        $q = "INSERT INTO " . DB_PREFIX . "category_description(category_id, language_id, `name`, description, meta_title) VALUES " .
            "($category_id, $base_lang, '$name', '$desc', '$name')";
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot write category: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot write: (" . $mysqli->errno . ") " . $mysqli->error;
        }

        // Обнуляем массив для составления пути категории
        $pathy = array();
        // Вычисляем путь
        getpath($category_id);
        // Разворачиваем его в обратную сторону
        $pathy = array_reverse($pathy);
        // Сохраняем путь в таблицу с путем, и записываем уровень каждой записи level, верхний уровень 0
        foreach ($pathy as $level => $catlevel) {
            $q = "INSERT INTO " . DB_PREFIX . "category_path(category_id, path_id, level) VALUES " .
                "($category_id, $catlevel, $level)";
            if (!$mysqli->query($q)) {
                fwrite($logfile, "Cannot write category: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
                echo "Cannot write: (" . $mysqli->errno . ") " . $mysqli->error;
            }
        }

        // Делаем записть в таблицу с магазинами
        // TODO тут надо будет определять, в какой магаз записывать
        $q = "INSERT INTO " . DB_PREFIX . "category_to_store(category_id, store_id) VALUES " .
            "($category_id, 0)";
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot write category: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot write: (" . $mysqli->errno . ") " . $mysqli->error;
        }

        // записываем это в лог temp
        fwrite($logfile, "c#$state>>> $category_id - $name - $parent_id - $link\n");
        echo "<p>c#$state>>> $category_id - $name - $parent_id - $link</p>\n";

    }

    // Проходим по массиву, определяем какие были удалены, записываем в лог
    foreach ($category_markup as $key => $item) {
        if (!isset($item['state'])) {
            $item['state'] = 'deleted';
            $category_markup[$key]['state'] = 'deleted';
            fwrite($logfile, "c#" . $item['state'] . ">>> " . $item['category_id'] . " - " . $item['name'] . " - " . $item['parent_id'] . "\n");
            echo "<p>c#" . $item['state'] . ">>> " . $item['category_id'] . " - " . $item['name'] . " - " . $item['parent_id'] . "</p>\n";
        };
    }
    // Удаляем из основной таблицы категорий те, которые были исключены из поставки
    $q = "DELETE FROM " . DB_PREFIX . "category WHERE category_id NOT IN (SELECT category_id FROM " . DB_PREFIX . "category_description)";
    if (!$mysqli->query($q)) {
        fwrite($logfile, "Cannot clear deleted category: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
        echo "Cannot clear deleted category: (" . $mysqli->errno . ") " . $mysqli->error;
    }

    // TODO лучше всего установить параметр в админке, который будет определять, что с ними (категориями) делать:
    // удалять, деактивировать, сохранять как было, если их нет в обновлении


    // Получаем текущую дата-время для записи в БД
    $date_now = date("Y-m-d H:i:s");

    // Очищаем дополнительные таблицы товаров.
    // В основной и в описании в конце удалим те, которые были исключены из обновления,
    // Предварительно сохранив их в лог
    $q = "TRUNCATE TABLE " . DB_PREFIX . "product_reward";
    if (!$mysqli->query($q)) {
        fwrite($logfile, "Cannot truncate product table: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
        echo "Cannot truncate product table: (" . $mysqli->errno . ") " . $mysqli->error;
    }
    $q = "TRUNCATE TABLE " . DB_PREFIX . "product_to_category";
    if (!$mysqli->query($q)) {
        fwrite($logfile, "Cannot truncate product table: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
        echo "Cannot truncate product table: (" . $mysqli->errno . ") " . $mysqli->error;
    }
    $q = "TRUNCATE TABLE " . DB_PREFIX . "product_to_store";
    if (!$mysqli->query($q)) {
        fwrite($logfile, "Cannot truncate product table: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
        echo "Cannot truncate product table: (" . $mysqli->errno . ") " . $mysqli->error;
    }


    // Проходим по массиву товаров
    foreach ($prods as $prod) {
        $product_id = $prod->getAttribute('id');                                        // product_id
        $available = $prod->getAttribute('available');                                  // доступность, true, false
        $url = $prod->getElementsByTagName('url')->item(0)->nodeValue;
        //TODO нужно будет считываеть спецификацию и все изображения товара по ссылке
        $category_id = $prod->getElementsByTagName('categoryId')->item(0)->nodeValue;   // category_id
        $price = (int)$prod->getElementsByTagName('price')->item(0)->nodeValue;        // price
        // Пересчитываем цену в соответствии с наценкой, в %
        $markup = array_search($category_id, array_column($category_markup, 'markup'));
        // Если наценка не установлена, ставим дефолтную, базовую
        if (!$markup) $markup = $base_markup;
        $price = (int)round($price * (1 + $markup / 100));
        // Если вдруг цена не указана (в файле такое встречается)
        if (!$price) $price = 0;

        // проверить, есть ли основное изображение
        $picnodes = $prod->getElementsByTagName('picture');
        if ($picnodes->length > 0) {
            // Получаем путь изображения
            $picture = $prod->getElementsByTagName('picture')->item(0)->nodeValue;      // image
            // разбиваем путь на части
            $path_parts = pathinfo($picture);
            // составляем локальный путь картинки
            $image = "catalog/product/" . $path_parts['filename'] . "." . $path_parts['extension'];
            // TODO сделать параметр, который определяет, надо ли скачивать заново файлы существующего товара
            if (!file_exists($_SERVER['DOCUMENT_ROOT']."/image/" . $image)) {
                copy($picture, $_SERVER['DOCUMENT_ROOT']."/image/" . $image);
            } else {
                // Возможная проверка на изменение изображения
                // Check changing file
                // $contents = file_get_contents($picture);
                // $md5file = md5($contents);
                // if ($md5file == md5_file("./image/".$image) - not change
                // echo "file exists! ";
                //copy($picture, "./image/".$image);
            }
        } else {
            // если изображения нет, вставляем пустое поле
            $image = "";
        }

        // Заменяем апострофы на кавычку. Можно заменять на код апострофа, в принципе
        $name = str_replace("'", '"', $prod->getElementsByTagName('name')->item(0)->nodeValue);                 // name         // meta_title
        // Получаем имя производителя manufacturer
        $vendor = $prod->getElementsByTagName('vendor')->item(0)->nodeValue;
        // код товара по производителю
        $model = $prod->getElementsByTagName('vendorCode')->item(0)->nodeValue;                                                // model
        // описание товара, короткое. полная спецификация берется со страницы товара!
        $desc = str_replace("'", '"', $prod->getElementsByTagName('description')->item(0)->nodeValue);          // description
        // TODO Штрикод - пока не используется, но можно привязать его, и выводить КОД ДЛЯ СКАНЕРА! можно печатать
        $barcode = $prod->getElementsByTagName('barcode')->item(0)->nodeValue;
        // TODO получить параметры товара и сверить их с существующими пармаметрами
        // TODO Если таких параметров в БД нет, то их надо создать, и записать для этого товара
        // TODO Если есть, то просто записать их
        // TODO Для этого надо сначала подгрузить таблицу с параметрами в скрипт

        // Запрашиваем этот товар в существующей таблице, если он есть, сравниваем с обновлением
        // Если отсутствует такой товар, то значит новый, добавляем, даты одинаковые
        // Если товар существует, то обновляем, и дату модификации
        // Дату устанавливаем с помощью PHP, чтобы она была одинакова
        // Ниже - это полный запрос, на получение всех данных товара для сверки
        /*$q = 'SELECT p.product_id, p.model, p.image, p.manufacturer_id, p.price, p.date_available, p.date_added, p.date_modified, d.name, d.description, c.category_id FROM `' . DB_PREFIX .
            'product` p JOIN `' . DB_PREFIX . 'product_description` d ON p.product_id = d.product_id JOIN `' . DB_PREFIX .
            'product_to_category` c ON (p.product_id = c.product_id) WHERE p.product_id = ' . $product_id;*/
        // Берем короткий запрос, для проверки наличия товара
        $q = 'SELECT product_id FROM `' . DB_PREFIX . 'product` WHERE product_id = ' . $product_id;
        // Этот статус не останется, т.к. мы не сверяем данные, будет либо changed либо new
        $state = "hold";
        if (!$result = $mysqli->query($q)) {
            throw new Exception('No product tables, or DB not available.');
        } else {
            if ($result->num_rows > 0) {
                //$product = $result->fetch_object();
                // сверять не будем, просто обновляем
                $state = "changed";
            } else {
                $state = "new";
            }
        }

        // сохраняем в основную таблицу
        if ($state == "new") {
            $q = "INSERT INTO " . DB_PREFIX . "product(product_id, model, quantity, stock_status_id, image, " .
                "manufacturer_id, price, tax_class_id, date_available, status, date_added, date_modified) VALUES " .
                "($product_id, '$model', 100, 7, '$image', 0, $price, 9, '$date_now', 1, '$date_now', '$date_now')";
        } else {
            $q = "UPDATE " . DB_PREFIX . "product SET model='$model', image='$image', " .
                "price=$price, date_modified='$date_now' WHERE product_id = $product_id";
        }
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot write product: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot write product: (" . $mysqli->errno . ") " . $mysqli->error;
        }

        // TODO Meta-description - туда загружаем короткое описание
        // TODO Спецификацию загружаем в description
        // TODO Либо проработать сохранение в специальную вкладку, которую можно создать с помощью materialize extension -  шаблоне
        // TODO Оттуда жк загружаем все дополнительные изображения в product_image
        // сохраняем описание товара
        if ($state == "new") {
            $q = "INSERT INTO " . DB_PREFIX . "product_description(product_id, language_id, `name`, description, " .
                "meta_title, meta_description) VALUES ($product_id, $base_lang, '$name', '$desc', '$name', '$desc')";
        } else {
            $q = "UPDATE " . DB_PREFIX . "product_description SET `name`='$name', description='$desc', " .
                "meta_title='$name', meta_description='$desc' WHERE product_id = $product_id";
        }
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot write product desc: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot write product desc: (" . $mysqli->errno . ") " . $mysqli->error;
        }

        // сохраняем таблицу с очками, которые даются за товар (ставим по умолчанию 0)
        $q = "INSERT INTO " . DB_PREFIX . "product_reward(product_id, customer_group_id, points) VALUES " .
            "($product_id, 1, 0)";
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot write product reward: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot write product reward: (" . $mysqli->errno . ") " . $mysqli->error;
        }

        // сохраняем связь товара с категорией
        // можно привязать показ товара во всех родительских категориях, если надо
        $q = "INSERT INTO " . DB_PREFIX . "product_to_category(product_id, category_id) VALUES " .
            "($product_id, $category_id)";
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot write product category: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot write product category: (" . $mysqli->errno . ") " . $mysqli->error;
        }

        // сохраняем связь с магазином
        // TODO в какой магазин сохранять - из настроек парсера, по умолчанию = 0 (основной)
        $q = "INSERT INTO " . DB_PREFIX . "product_to_store(product_id, store_id) VALUES " .
            "($product_id, 0)";
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot write product store: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot write product store: (" . $mysqli->errno . ") " . $mysqli->error;
        }

        // Сохраняем в лог temp внесение записи о товаре
        fwrite($logfile, "p#$state>>> $product_id - $name - $category_id - $picture\n");
        echo "<p>p#$state>>> $product_id - $name - $category_id - $picture</p>\n";
    }

    // Делаем запрос в базу, на предмет тех товаров, которых удалены из обновления
    // Вычислять товар можно как по дате, так и по дополнительным таблицам, которые очищались от записей
    $q = "SELECT p.product_id, p.model, p.image, p.manufacturer_id, p.price, p.date_added, d.name FROM `" . DB_PREFIX .
        "product` p JOIN `" . DB_PREFIX . "product_description` d ON p.product_id = d.product_id " .
        "WHERE p.date_modified <> '" . $date_now . "'";
    if (!$result = $mysqli->query($q)) {
        // Ошибка при работе с таблицей товаров! Недоступна?
        fwrite($logfile, 'Error while connect to product tables, possible DB not available');
        throw new Exception('Error while connect to product tables, possible DB not available');
    } else {
        while ($obj = $result->fetch_assoc()) {
            // Для каждого удаляем файл изображения
            unlink($_SERVER['DOCUMENT_ROOT']."/image/".$obj['image']);
            // Потом просматриваем таблицу с изображениями и также удаляем
            $q = "SELECT image FROM " . DB_PREFIX . "product_image WHERE product_id = " . $obj['product_id'];
            if ($rimg = $mysqli->query($q)) {
                while ($oimg = $rimg->fetch_assoc()) {
                    unlink($_SERVER['DOCUMENT_ROOT']."/image/".$oimg['image']);
                }
                // Удаляем записи об этих изображениях
                $q = "DELETE FROM " . DB_PREFIX . "product_image WHERE product_id = " . $obj['product_id'];
                if (!$mysqli->query($q)) {
                    fwrite($logfile, "Cannot clear product add image: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
                    echo "Cannot clear product add image: (" . $mysqli->errno . ") " . $mysqli->error;
                }
            }
            // Сохраняем удаляемый товар в лог
            fwrite($logfile, "p#deleted>>> " . $obj['product_id'] . " - " . $obj['name'] . " - " . $obj['image'] . "\n");
            echo "<p>deleted - " . $obj['product_id'] . " - " . $obj['name'] . " - " . $obj['image'] . "</p>>\n";
            //print_r($obj);
        }
        // Удаляем эти записи из основной таблицы и из таблицы описаний и дополнительные картинки
        $q = "DELETE FROM " . DB_PREFIX . "product_description WHERE product_id IN (SELECT product_id FROM " . DB_PREFIX . "product WHERE date_modified <> '".$date_now."')";
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot clear deleted products: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot clear deleted products: (" . $mysqli->errno . ") " . $mysqli->error;
        }
        $q = "DELETE FROM " . DB_PREFIX . "product WHERE date_modified <> '".$date_now."'";
        if (!$mysqli->query($q)) {
            fwrite($logfile, "Cannot clear deleted products: (" . $mysqli->errno . ") " . $mysqli->error . "\n");
            echo "Cannot clear deleted products: (" . $mysqli->errno . ") " . $mysqli->error;
        }
    }

    // переименовываем temp log с именем в которое входит дата и время создания
    rename('temp', date("Y-m-d_H-i").".log");

    // можно имя использовать для определения срока в админке, либо по атрибутам файла
    // также в админке выводить что происходило с категориями и товарами

} catch (Throwable $e) {
    echo '<p class="error">ERROR: ' . $e->getMessage();
    // запись в лог работы парсера
    fwrite($logfile, "ERROR: " . $e->getMessage() . "\n");
    //print_r($e);
} catch (Exception $e) {
    echo '<p class="exception">EXCEPTION: ' . $e->getMessage() . '</p>';
    // запись в лог работы парсера
    fwrite($logfile, "EXCEPTION: " . $e->getMessage() . "\n");
    //print_r($e);
}

fclose($logfile);

?>

</body>
</html>
