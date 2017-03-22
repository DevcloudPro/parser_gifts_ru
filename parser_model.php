<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Parser_model extends CI_Model {
  private $prefix = 'wp_';
  private $domain = 'in-gifts.ru';
  private $thumbnail_path = '../thumbnails/';
  private $upload_path = '../wp-content/uploads/';

  function __construct(){
    parent::__construct();


    $this->xml_cache_path = 'application/cache/xml/';



    ## Load libs
    include('simple_html_dom.php');
  }


  public function xml_cache_save($file_name, $xml){
    $result = null;

    $fp = fopen($this->xml_cache_path . $file_name . '.xml', "w+");
    if(fwrite($fp, $xml)){
      $result = 'Writing to file has success';
    }else{
      $result = 'Writing to file has error';
    }
    fclose($fp);

    return $result;
  }


  public function xml_cache_get($file_name){
    $result = file_get_contents($this->xml_cache_path . $file_name . '.xml');

    return $result;
  }




  ## Post
  public function set_product($data = array()){
    $data->content = (string) $data->content;

    ## Edit post content
    $html = str_get_html($data->content);

    $table = $html->find('table', 0);

    

    if($table){
      $table_html = $table->outertext;


      ## Set to options
      $size = new stdClass();
      $size->name = 'Размер';

      $size->children = array();

      $i = 0;
      foreach ($table->find('tr') as $key => $value) {
        if($key == 0){
          foreach ($value->find('th') as $_key => $_value) {
            if(isset($_value->find('div', 0)->outertext)){
              ++ $i;

              $__value = new stdClass();


              $_offset = $_value->find('div', 0)->outertext;
              $_size = str_replace($_offset, '', $_value->outertext);
              $_size = str_replace(array(
                '<th>',
                '</th>'
              ), '', $_size);

              $__value->name = $_size;


              $size->children[] = $__value;
            }
          }
        }
      }


      if(count($size->children)) $data->options->{'Размер'} = $size;


      ## Set content
      $data->content = str_replace(array(
        $table_html,
        'Таблица размеров:'
      ), '', $data->content);
    }

    $html->clear();






    $this->db->where('post_title', $data->name);

    $posts = $this->db->get($this->prefix . 'posts')->result();


    $result = new stdClass();

    $result->data = $data;

    ## Set post
    if(count($posts)){
      $post = $posts[0];

      $result->post = $post;

      $post_id = $this->update_post($data, $post);

      $result->update_post = $post_id;
    }else{
      $post_id = $this->insert_post($data);

      $result->insert_post = $post_id;


      ## Get created post
      $this->db->where('ID', $post_id);

      $posts = $this->db->get($this->prefix . 'posts')->result();
      $post = $posts[0];
    }


    ## Set thumbnail
    $result->set_thumbnail = $this->set_thumbnail($data->super_big_image->{'@attributes'}->src);

    ## Set post thumbnail
    if(isset($result->set_thumbnail->post_id)) $result->set_post_thumbnail = $this->set_post_thumbnail($post_id, $result->set_thumbnail->post_id);


    ## Set attributes
    if(isset($data->stock_amount)) $result->set_stock_status = $this->set_attribute($post_id, '_stock_status', $data->stock_amount > 0 ? 'instock' : 'outstock');
    if(isset($data->weight)) $result->set_weight = $this->set_attribute($post_id, '_weight', $data->weight);
    if(isset($data->code)) $result->set_sku = $this->set_attribute($post_id, '_sku', $data->code);
    if(isset($data->group)) $result->set_group_id = $this->set_attribute($post_id, '_group_id', $data->group);
    if(isset($data->pack->sizex)) $result->set_length = $this->set_attribute($post_id, '_length', $data->pack->sizex);
    if(isset($data->pack->sizey)) $result->set_width = $this->set_attribute($post_id, '_width', $data->pack->sizey);
    if(isset($data->pack->sizez)) $result->set_height = $this->set_attribute($post_id, '_height', $data->pack->sizez);
    

    ## Set stock
    if(isset($data->stock_amount)) $result->set_stock = $this->set_attribute($post_id, '_stock', $data->stock_amount);
    if(isset($data->stock_free)) $result->set_stock = $this->set_attribute($post_id, '_stock_free', $data->stock_free);
    if(isset($data->stock_inwayamount)) $result->set_stock = $this->set_attribute($post_id, '_stock_inwayamount', $data->stock_inwayamount);

    ## Price
    if(isset($data->price->price)){
      $result->set_regular_price = $this->set_attribute($post_id, '_regular_price', $data->price->price);
      $result->set_price = $this->set_attribute($post_id, '_price', $data->price->price);

      $result->set_max_variation_regular_price = $this->set_attribute($post_id, '_max_variation_regular_price', $data->price->price);
      $result->set_min_variation_regular_price = $this->set_attribute($post_id, '_min_variation_regular_price', $data->price->price);
      $result->set_max_variation_price = $this->set_attribute($post_id, '_max_variation_price', $data->price->price);
      $result->set_min_variation_price = $this->set_attribute($post_id, '_min_variation_price', $data->price->price);
    }


    ## static data
    $result->set_manage_stock = $this->set_attribute($post_id, '_manage_stock', 'yes');
    $result->set_visibility = $this->set_attribute($post_id, '_visibility', 'visible');
    $result->set_tax_status = $this->set_attribute($post_id, '_tax_status', 'taxable');



    



    ## Set product to category
    $result->set_term_relationships = $this->set_term_relationships($post_id, $data->page_id);

    


    ## Set options
    if(isset($data->options)){
      $result->set_options = $this->set_options($post_id, $data->options);


      ## Set variations
      if(count($result->set_options->variations)){
        ## Set product type
        $result->set_term_relationships_product_type = $this->set_term_relationships($post_id, 5);

        $id = 0;
        foreach ($result->set_options->variations as $key => $value) {
          ++ $id;

          $post_name = 'product-' . $post_id . '-variation' . ($id > 1 ? ('-' . $id) : '');


          $this->db->where('post_parent', $post_id);
          $this->db->where('post_type', 'product_variation');
          $this->db->where('post_name', $post_name);

          $_posts = $this->db->get($this->prefix . 'posts')->result();


          ## change data
          $data->seo_name = $post_name;
          $data->name = 'Product #' . $post_id . ' Variation';

          ## Set post
          if(count($_posts)){
            $_post = $_posts[0];

            $result->post_variation = $_post;

            $post_variation_id = $this->update_post($data, $_post);
          }else{
            $post_variation_id = $this->insert_post($data, $post_id);


            ## Get created post
            $this->db->where('ID', $post_variation_id);

            $_posts = $this->db->get($this->prefix . 'posts')->result();
            $_post = $_posts[0];
          }

          ## Set attributes
          if(isset($data->stock_amount)) $result->set_variation_stock_status = $this->set_attribute($post_variation_id, '_stock_status', $data->stock_amount > 0 ? 'instock' : 'outstock');

          if(isset($data->price->price)){
            $result->set_variation_regular_price = $this->set_attribute($post_variation_id, '_regular_price', $data->price->price);
            $result->set_variation_price = $this->set_attribute($post_variation_id, '_price', $data->price->price);

            $result->set_variation_max_regular_price_variation_id = $this->set_attribute($post_id, '_max_regular_price_variation_id', $post_variation_id);
            $result->set_variation_min_regular_price_variation_id = $this->set_attribute($post_id, '_min_regular_price_variation_id', $post_variation_id);
            $result->set_max_price_variation_id = $this->set_attribute($post_id, '_max_price_variation_id', $post_variation_id);
            $result->set_min_price_variation_id = $this->set_attribute($post_id, '_min_price_variation_id', $post_variation_id);
          }



          if(isset($value->attribute_pa_tsvet)){
            $result->set_variation_attribute_pa_tsvet = $this->set_attribute($post_variation_id, 'attribute_pa_tsvet', $value->attribute_pa_tsvet->name);


            ## Set relationships
            //$result->set_variation_term_relationships_attribute_pa_tsvet = $this->set_term_relationships($post_variation_id, $value->attribute_pa_tsvet->term_taxonomy_id);        
          }

          if(isset($value->attribute_pa_product_size)){
            $result->set_variation_attribute_pa_product_size = $this->set_attribute($post_variation_id, 'attribute_pa_product_size', $value->attribute_pa_product_size->name);

            ## Set relationships
            //$result->set_variation_term_relationships_attribute_pa_product_size = $this->set_term_relationships($post_variation_id, $value->attribute_pa_product_size->term_taxonomy_id); 
          }


          ## static attributes
          $result->set_variation_sale_price_dates_to = $this->set_attribute($post_variation_id, '_sale_price_dates_to', '');
          $result->set_variation_sale_price_dates_from = $this->set_attribute($post_variation_id, '_sale_price_dates_from', '');
          $result->set_variation_sale_price = $this->set_attribute($post_variation_id, '_sale_price', '');
        }
      }
    }


    return $result;
  }


  private function insert_post($data, $parent_post_id = 0){
    ## Get seo posts
    $this->db->like('post_name', $data->seo_name);

    $posts = $this->db->get($this->prefix . 'posts')->result();
    $count = count($posts);

    if($count){
      ++ $count;

      $data->seo_name = $data->seo_name . '-' . $count;
    }


    ## Set data
    $date = date('Y-m-d H:i:s');

    $post_type = 'product';
    $comment_status = 'publish';

    if($parent_post_id){
      $post_type = 'product_variation';
      $comment_status = 'closed';
    }


    $this->db->set(array(
      'post_author' => 3,
      'post_date' => $date,
      'post_date_gmt' => $date,
      'post_content' => $data->content,
      'post_title' => $data->name,
      'post_status' => 'publish',
      'ping_status' => 'closed',
      'comment_status' => $comment_status,
      'post_name' => $data->seo_name,
      'post_modified' => $date,
      'post_modified_gmt' => $date,
      'guid' => 'http://' . $this->domain . '/product/' . $data->seo_name,
      'post_type' => $post_type,
      'post_parent' => $parent_post_id
    ));

    $this->db->insert($this->prefix . 'posts');

    return $this->db->insert_id();
  }

  private function update_post($data, $post_data){
    ## Set data
    $date = date('Y-m-d H:i:s');

    $this->db->set(array(
      'post_content' => $data->content,
      'post_title' => $data->name,
      'post_modified' => $date,
      'post_modified_gmt' => $date
    ));

    $this->db->where('ID', $post_data->ID);

    $this->db->update($this->prefix . 'posts');

    return $post_data->ID;
  }






  ## Options
  private function set_options($post_id, $data){
    $result = new stdClass();


    $options = new stdClass();
    $options->sizes = array();
    $options->colors = array();


    $result->set_term = array();



    $_product_attributes = array();

    $position = 0;
    foreach ($data as $key => $value) {
      $name = mb_strtolower($value->name);
      $is_variation = 0;
      $is_visible = 1;

      $_name = null;

      switch ($name) {
        case 'материал':
          $_name = 'pa_matherial';
        break;

        case 'бренд':
          $_name = 'pa_brand';
        break;

        case 'объем памяти':
          $_name = 'pa_product_size';
        break;

        case 'размер':
          $_name = 'pa_product_size';
          $is_variation = 1;
          $is_visible = 0;
        break;

        case 'цвет':
          $_name = 'pa_tsvet';
          $is_variation = 1;
          $is_visible = 0;
        break;
      }

      if($_name){
        $_value = array(
          'name' => $_name,
          'value' => '',
          'position' => $position,
          'is_visible' => $is_visible,
          'is_variation' => $is_variation,
          'is_taxonomy' => 1
        );




        $_product_attributes[$_name] = $_value;



        ## Set term data
        foreach ($value->children as $_key => $_value) {
          $prop = null;

          if($_name == 'pa_tsvet'){
            if(isset($_value->color)) $prop = $_value->color;
          }


          $set_term = $this->set_term($_value->name, $prop);

          $result->set_term[] = $set_term;


          ## Set term taxonomy
          $set_term_taxonomy = $this->set_term_taxonomy($set_term->term_id, $_name);

          $result->set_term_taxonomy[] = $set_term_taxonomy;


          ## Set term relationships
          $set_term_relationships = $this->set_term_relationships($post_id, $set_term_taxonomy->term_taxonomy_id);

          $result->set_term_relationships[] = $set_term_relationships;



          ## Set variation
          if($is_variation){
            $__value = new stdClass();

            $__value->term_taxonomy_id = $set_term_taxonomy->term_taxonomy_id;

            if($_name == 'pa_tsvet'){
              $__value->name = $this->seo($_value->name);

              $options->colors[] = $__value;
            }else if($_name == 'pa_product_size'){
              $__value->name = mb_strtolower($_value->name);

              $options->sizes[] = $__value;
            }
          }
        }




        ++ $position;
      }
    }

    ## Set attributes
    $result->set_attribute = $this->set_attribute($post_id, '_product_attributes', serialize($_product_attributes));


    $variations = array();

    if(count($options->colors) && count($options->sizes)){
      foreach ($options->colors as $key => $value) {
        foreach ($options->sizes as $_key => $_value) {
          $variation = new stdClass();
          $variation->attribute_pa_tsvet = $value;
          $variation->attribute_pa_product_size = $_value;


          $variations[] = $variation;
        }
      }
    }else{
      if(count($options->colors)){
        foreach ($options->colors as $key => $value) {
          $variation = new stdClass();
          $variation->attribute_pa_tsvet = $value;


          $variations[] = $variation;
        }
      }else if(count($options->sizes)){
        foreach ($options->sizes as $key => $value) {
          $variation = new stdClass();
          $variation->attribute_pa_product_size = $value;


          $variations[] = $variation;
        }
      }
    }


    $result->variations = $variations;


    return $result;
  }




  ## Term
  private function set_term($name, $prop){
    $seo_name = $this->seo($name);

    if($prop){
      $name = $name . '|' . $prop;
    }


    $result = new stdClass();


    $this->db->where('name', $name);

    $terms = $this->db->get($this->prefix . 'terms')->result();

    if(count($terms)){
      $terms = $terms[0];


      $term_id = $this->update_term($name, $seo_name, $terms);

      $result->update_term = $term_id;
    }else{
      ## insert

      $term_id = $this->insert_term($name, $seo_name);

      $result->insert_term = $term_id;
    }


    $result->term_id = $term_id;


    return $result;
  }


  private function insert_term($name, $seo_name){
    $this->db->set(array(
      'name' => $name,
      'slug' => $seo_name
    ));

    $this->db->insert($this->prefix . 'terms');

    return $this->db->insert_id();
  }

  private function update_term($name, $seo_name, $terms){
    $this->db->set(array(
      'name' => $name,
      'slug' => $seo_name
    ));

    $this->db->where('term_id', $terms->term_id);

    $this->db->update($this->prefix . 'terms');

    return $terms->term_id;
  }





  private function set_term_taxonomy($term_id, $taxonomy){
    $result = new stdClass();


    $this->db->where('term_id', $term_id);
    $this->db->where('taxonomy', $taxonomy);

    $term_taxonomy = $this->db->get($this->prefix . 'term_taxonomy')->result();

    if(count($term_taxonomy)){
      $term_taxonomy = $term_taxonomy[0];


      $term_taxonomy_id = $this->update_term_taxonomy($term_id, $taxonomy, $term_taxonomy);

      $result->update_term_taxonomy = $term_taxonomy_id;
    }else{
      ## insert

      $term_taxonomy_id = $this->insert_term_taxonomy($term_id, $taxonomy);

      $result->insert_term_taxonomy = $term_taxonomy_id;
    }


    $result->term_taxonomy_id = $term_taxonomy_id;


    return $result;
  }


  private function insert_term_taxonomy($term_id, $taxonomy){
    $this->db->set(array(
      'term_id' => $term_id,
      'taxonomy' => $taxonomy,
      'count' => 1
    ));

    $this->db->insert($this->prefix . 'term_taxonomy');

    return $this->db->insert_id();
  }

  private function update_term_taxonomy($term_id, $taxonomy, $term_taxonomy){
    $this->db->set(array(
      'term_id' => $term_id,
      'taxonomy' => $taxonomy,
      'count' => 1
    ));

    $this->db->where('term_taxonomy_id', $term_taxonomy->term_taxonomy_id);

    $this->db->update($this->prefix . 'term_taxonomy');

    return $term_taxonomy->term_taxonomy_id;
  }







  private function set_term_relationships($post_id, $page_id){
    $result = new stdClass();


    $this->db->where('object_id', $post_id);
    $this->db->where('term_taxonomy_id', $page_id);

    $term_relationships = $this->db->get($this->prefix . 'term_relationships')->result();

    if(count($term_relationships)){
      $term_relationships = $term_relationships[0];


      $term_relationships_id = $this->update_term_relationships($post_id, $page_id, $term_relationships);

      $result->update_term_relationships = $term_relationships_id;
    }else{
      ## insert

      $term_relationships_id = $this->insert_term_relationships($post_id, $page_id);

      $result->insert_term_relationships = $term_relationships_id;
    }


    $result->term_relationships_id = $term_relationships_id;


    return $result;
  }


  private function insert_term_relationships($post_id, $page_id){
    $this->db->set(array(
      'object_id' => $post_id,
      'term_taxonomy_id' => $page_id
    ));

    $this->db->insert($this->prefix . 'term_relationships');

    return $post_id;
  }

  private function update_term_relationships($post_id, $page_id, $term_relationships){
    return $term_relationships->object_id;
  }




  ## Set attributes
  private function set_attribute($post_id, $key, $value){
    $result = new stdClass();

    $this->db->where('post_id', $post_id);
    $this->db->where('meta_key', $key);

    $postmeta = $this->db->get($this->prefix . 'postmeta')->result();

    if(count($postmeta)){
      $postmeta = $postmeta[0];

      $meta_id = $this->update_attribute($post_id, $key, $value, $postmeta);

      $result->update_attribute = $meta_id;
    }else{
      $meta_id = $this->insert_attribute($post_id, $key, $value);

      $result->insert_attribute = $meta_id;

      ## get attribute
      $this->db->where('meta_id', $meta_id);

      $postmeta = $this->db->get($this->prefix . 'postmeta')->result();
      $postmeta = $postmeta[0];
    }

    $result->meta_id = $meta_id;



    return $result;
  }

  private function insert_attribute($post_id, $key, $value){
    $this->db->set(array(
      'post_id' => $post_id,
      'meta_key' => $key,
      'meta_value' => $value
    ));

    $this->db->insert($this->prefix . 'postmeta');

    return $this->db->insert_id();
  }

  private function update_attribute($post_id, $key, $value, $postmeta){
    $this->db->set(array(
      'post_id' => $post_id,
      'meta_key' => $key,
      'meta_value' => $value
    ));

    $this->db->where('meta_id', $postmeta->meta_id);

    $this->db->update($this->prefix . 'postmeta');

    return $postmeta->meta_id;
  }




  ## Post Thumbnail
  private function set_post_thumbnail($post_id, $meta_post_id){
    $result = new stdClass();

    $this->db->where('post_id', $post_id);
    $this->db->where('meta_key', '_thumbnail_id');
    $this->db->where('meta_value', $meta_post_id);

    $postmeta = $this->db->get($this->prefix . 'postmeta')->result();   

    if(count($postmeta)){
      $postmeta = $postmeta[0];

      $meta_id = $this->update_post_thumbnail($post_id, $meta_post_id, $postmeta);

      $result->update_post_thumbnail = $meta_id;
    }else{
      $meta_id = $this->insert_post_thumbnail($post_id, $meta_post_id);

      $result->insert_post_thumbnail = $meta_id;


      ## Get created postmeta
      $this->db->where('meta_id', $meta_id);

      $postmeta = $this->db->get($this->prefix . 'postmeta')->result();
      $postmeta = $postmeta[0];
    }


    $result->meta_id = $meta_id;
    $result->postmeta = $postmeta;


    return $result;
  }

  private function insert_post_thumbnail($post_id, $meta_post_id){
    $this->db->set(array(
      'post_id' => $post_id,
      'meta_key' => '_thumbnail_id',
      'meta_value' => $meta_post_id
    ));

    $this->db->insert($this->prefix . 'postmeta');

    return $this->db->insert_id();
  }

  private function update_post_thumbnail($post_id, $meta_post_id, $postmeta){
    $this->db->set(array(
      'post_id' => $post_id,
      'meta_key' => '_thumbnail_id',
      'meta_value' => $meta_post_id
    ));

    $this->db->where('meta_id', $postmeta->meta_id);

    $this->db->update($this->prefix . 'postmeta');

    return $postmeta->meta_id;
  }






  ## Thumbnail
  private function set_thumbnail($image){
    $result = new stdClass();


    $_image = str_replace('thumbnails/', '', $image);

    $_image = str_replace('/', '-', $_image);


    if(file_exists($this->thumbnail_path . $_image) == false){
      $image_stream = $this->curl->get('catalogue/' . $image);



      $fp = fopen($this->thumbnail_path . $_image, "w+");

      fwrite($fp, $image_stream);
      fclose($fp);
    }

    if(file_exists($this->upload_path . 'products/' . $_image) == false){
      $image_stream = file_get_contents($this->thumbnail_path . $_image);


      $fp = fopen($this->upload_path . 'products/' . $_image, "w+");

      fwrite($fp, $image_stream);
      fclose($fp);
    }



    $this->db->like('meta_value', $_image);
    $this->db->where('meta_key', '_wp_attached_file');

    $postmeta = $this->db->get($this->prefix . 'postmeta')->result();


    $this->db->like('meta_value', $_image);
    $this->db->where('meta_key', '_wp_attachment_metadata');

    $_postmeta = $this->db->get($this->prefix . 'postmeta')->result();


    if(count($postmeta) && count($_postmeta)){
      $postmeta = $postmeta[0];
      $_postmeta = $_postmeta[0];


      $thumbnail = $this->update_thumbnail($_image, $postmeta, $_postmeta);

      $result->update_thumbnail = $thumbnail;
    }else{
      $thumbnail = $this->insert_thumbnail($_image);

      $result->insert_thumbnail = $thumbnail;


      ## Get created postmeta
      $this->db->where('meta_id', $thumbnail->meta_id);

      $postmeta = $this->db->get($this->prefix . 'postmeta')->result();
      $postmeta = $postmeta[0];



      $this->db->where('meta_id', $thumbnail->_meta_id);

      $_postmeta = $this->db->get($this->prefix . 'postmeta')->result();
      $_postmeta = $_postmeta[0];
    }


    $result->meta_id = $thumbnail->meta_id;
    $result->_meta_id = $thumbnail->_meta_id;
    $result->post_id = $thumbnail->post_id;
    $result->postmeta = $postmeta;
    $result->_postmeta = $_postmeta;


    


    return $result;
  }


  
  private function insert_thumbnail($image){
    $result = new stdClass();

    ## Set data
    $date = date('Y-m-d H:i:s');

    $this->db->set(array(
      'post_author' => 3,
      'post_date' => $date,
      'post_date_gmt' => $date,
      'post_title' => 'gallery desc',
      'post_status' => 'inherit',
      'ping_status' => 'closed',
      'comment_status' => 'open',
      'post_name' => $image,
      'post_modified' => $date,
      'post_modified_gmt' => $date,
      'guid' => 'http://' . $this->domain . '/wp-content/uploads/products/' . $image,
      'post_type' => 'attachment',
      'post_mime_type' => 'image/jpeg'
    ));

    $this->db->insert($this->prefix . 'posts');

    $post_id = $this->db->insert_id();

    $result->post_id = $post_id;

    ## Insert attach
    $data = array(
      'width' => 1000,
      'height' => 1000,
      'file' => 'products/' . $image,
      'image_meta' => array (
        'aperture' => '0',
        'credit' => '',
        'camera' => '',
        'caption' => '',
        'created_timestamp' => '0',
        'copyright' => '',
        'focal_length' => '0',
        'iso' => '0',
        'shutter_speed' => '0',
        'title' => '',
        'orientation' => '0',
        'keywords' => array(),
      )
    );

    $this->db->set(array(
      'post_id' => $post_id,
      'meta_key' => '_wp_attachment_metadata',
      'meta_value' => serialize($data)
    ));

    $this->db->insert($this->prefix . 'postmeta');

    $result->meta_id = $this->db->insert_id();



    $this->db->set(array(
      'post_id' => $post_id,
      'meta_key' => '_wp_attached_file',
      'meta_value' => 'products/' . $image
    ));

    $this->db->insert($this->prefix . 'postmeta');

    $result->_meta_id = $this->db->insert_id();



    return $result;
  }


  private function update_thumbnail($image, $postmeta, $_postmeta){
    $result = new stdClass();

    ## Set data
    $date = date('Y-m-d H:i:s');

    $this->db->set(array(
      'post_name' => $image,
      'post_modified' => $date,
      'post_modified_gmt' => $date,
      'guid' => 'http://' . $this->domain . '/wp-content/uploads/products/' . $image,
    ));

    $this->db->where('ID', $postmeta->post_id);

    $result->post_id = $postmeta->post_id;

    $this->db->update($this->prefix . 'posts');



    ## Update attach
    $data = array(
      'width' => 1000,
      'height' => 1000,
      'file' => 'products/' . $image,
      'image_meta' => array (
        'aperture' => '0',
        'credit' => '',
        'camera' => '',
        'caption' => '',
        'created_timestamp' => '0',
        'copyright' => '',
        'focal_length' => '0',
        'iso' => '0',
        'shutter_speed' => '0',
        'title' => '',
        'orientation' => '0',
        'keywords' => array(),
      )
    );

    $this->db->set(array(
      'post_id' => $postmeta->post_id,
      'meta_key' => '_wp_attachment_metadata',
      'meta_value' => serialize($data)
    ));

    $this->db->where('meta_id', $postmeta->meta_id);

    $this->db->update($this->prefix . 'postmeta');

    $result->meta_id = $postmeta->meta_id;



    $this->db->set(array(
      'post_id' => $_postmeta->post_id,
      'meta_key' => '_wp_attached_file',
      'meta_value' => 'products/' . $image
    ));

    $this->db->where('meta_id', $_postmeta->meta_id);

    $this->db->update($this->prefix . 'postmeta');

    $result->_meta_id = $_postmeta->meta_id;


    return $result;
  }



  







  public function get_xml_data(){
    $products = $this->xml_to_data();
    $stock = $this->xml_to_data('stock');

    $tree = $this->get_tree_xml_data();
    $filters = $this->get_filters_xml_data();

    foreach($products as $key => $value){
      if(isset($stock[$key]) && isset($tree[$key])){
        ## Get stock
        $_stock = $stock[$key];

        $value->stock_amount = isset($_stock->amount) ? $_stock->amount : '0';
        $value->stock_free = isset($_stock->free) ? $_stock->free : '0';
        $value->stock_inwayamount = isset($_stock->inwayamount) ? $_stock->inwayamount : '0';



        ## Get tree
        $_tree = $tree[$key];

        $value->page_id = (int) $_tree->page;
        $value->page_name = (string) $_tree->name;
        $value->seo_page_name = $this->seo($value->page_name);

        $products[$key] = $value;


        ## Other replacement
        $value->seo_name = $this->seo($value->name);


        ## Filters
        if(isset($value->filters->filter)){
          $options = array();

          foreach ($value->filters->filter as $_key => $_value) {
            if(isset($_value->filtertypeid)){
              $_parent = $filters[$_value->filtertypeid];

              if(empty($options[$_parent->name])){
                $__value = new stdClass();

                $__value->name = $_parent->name;
                $__value->children = array();


                ## set
                $options[$_parent->name] = $__value;
              }



              $options[$_parent->name]->children[] = $_parent->childrens[$_value->filterid];
            }

            if(isset($options[$_parent->name])){
              if(count($options[$_parent->name]->children) == 0){
                unset($options[$_parent->name]);
              }
            }
          }


          $value->options = $options;
        }
      }else{
        unset($products[$key]);
      }
    }

    return $this->_json($products);
  }

  public function get_tree_xml_data(){
    $tree = $this->xml_tree_to_data();

    $result = array();

    foreach ($tree as $key => $value) {
      foreach ($value->page as $_key => $_value) {
        foreach ($_value->page as $__key => $__value) {
          foreach ($__value->product as $___key => $___value) {
            $___value->name = $__value->name;

            $result[(int) $___value->product] = $___value;
          }
        }
      }
    }
    

    return $result;
  }


  public function get_filters_xml_data(){
    $filters = $this->xml_filters_to_data();

    ## Create colors
    $colors = new stdClass();

    $colors->{'хаки'} = '78866B';
    $colors->{'сиреневый'} = 'C7A1C7';
    $colors->{'лиловый'} = 'DB7094';
    $colors->{'оранжевый'} = 'FFA600';
    $colors->{'кобальт'} = '0047AB';
    $colors->{'темно-синий'} = '002238';
    $colors->{'фиолетовый'} = '8C00FF';
    $colors->{'розовый'} = 'FFBFCA';
    $colors->{'черный'} = '000000';
    $colors->{'голубой'} = '00BFFF';
    $colors->{'антрацит'} = '293133';
    $colors->{'серый'} = '808080';
    $colors->{'милитари'} = '362021';
    $colors->{'бежевый'} = 'F5F5DC';
    $colors->{'серебристый'} = 'C9C0BB';
    $colors->{'синий'} = '0000FF';
    $colors->{'красный'} = 'FF0000';
    $colors->{'зеленый'} = '008000';
    $colors->{'золотой'} = 'FFD900';
    $colors->{'бирюзовый'} = '31D6C8';
    $colors->{'желтый'} = 'FFFF00';
    $colors->{'зеленое яблоко'} = '5CF249';
    $colors->{'серебряный'} = 'BFBFBF';
    $colors->{'коричневый'} = '964B00';
    $colors->{'бронзовый'} = 'CC7E31';
    $colors->{'белый'} = 'FFFFFF';
    $colors->{'серый меланж'} = '808080';
    $colors->{'лимонный'} = 'FCE90F';
    $colors->{'бордовый'} = 'B00000';
    $colors->{'бордо'} = 'A62929';
    $colors->{'малиновый'} = 'DB143C';
    $colors->{'золотистый'} = 'FFEC8C';





    $result = array();

    foreach ($filters as $key => $value) {
      $_value = new stdClass();

      $_value->id = $value->filtertypeid;
      $_value->name = $value->filtertypename;

      $_value->childrens = array();

      foreach ($value->filters->filter as $__key => $__value) {
        $___value = new stdClass();

        $___value->id = $__value->filterid;
        $___value->name = $__value->filtername;

        ## If this color
        if(mb_strtolower($_value->name) == 'цвет'){
          if(isset($colors->{mb_strtolower($___value->name)})){
            $___value->color = $colors->{mb_strtolower($___value->name)};
          }
        }


        $_value->childrens[$__value->filterid] = $___value;
      }


      $result[$value->filtertypeid] = $_value;
    }
    

    return $result;
  }





  private function xml_to_data($action = 'product'){
    $xml_data = $this->parser_model->xml_cache_get($action);
    $xml = simplexml_load_string($xml_data);

    $result = array();

    foreach ($xml as $key => $value) {
      $result[(int) $value->product_id] = $this->_json($value);
    }

    return $result;
  }

  private function xml_tree_to_data(){
    $xml_data = $this->parser_model->xml_cache_get('tree');
    $xml = simplexml_load_string($xml_data);

    $result = array();

    foreach ($xml as $key => $value) {
      $result[(int) $value->page_id] = $value;
    }

    return $result;
  }

  private function xml_filters_to_data(){
    $xml_data = $this->parser_model->xml_cache_get('filters');
    $xml = simplexml_load_string($xml_data);

    $result = array();

    foreach ($xml->filtertypes->filtertype as $key => $value) {
      $result[(int) $value->filtertypeid] = $this->_json($value);
    }

    return $result;
  }




  public function seo($string) {
    $converter = array(
      'а' => 'a',   'б' => 'b',   'в' => 'v',
      'г' => 'g',   'д' => 'd',   'е' => 'e',
      'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
      'и' => 'i',   'й' => 'y',   'к' => 'k',
      'л' => 'l',   'м' => 'm',   'н' => 'n',
      'о' => 'o',   'п' => 'p',   'р' => 'r',
      'с' => 's',   'т' => 't',   'у' => 'u',
      'ф' => 'f',   'х' => 'h',   'ц' => 'c',
      'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
      'ь' => '',    'ы' => 'y',   'ъ' => '',
      'э' => 'e',   'ю' => 'yu',  'я' => 'ya',
      'А' => 'A',   'Б' => 'B',   'В' => 'V',
      'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
      'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
      'И' => 'I',   'Й' => 'Y',   'К' => 'K',
      'Л' => 'L',   'М' => 'M',   'Н' => 'N',
      'О' => 'O',   'П' => 'P',   'Р' => 'R',
      'С' => 'S',   'Т' => 'T',   'У' => 'U',
      'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
      'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
      'Ь' => '',    'Ы' => 'Y',   'Ъ' => '',
      'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
      ' ' => '-'
    );


    $result = strtr($string, $converter);

    $result = preg_replace("/[^a-zA-Z0-9\-]/", "", $result);
    $result = mb_strtolower($result);

    return $result;
  }


  private function _json($data = array()){
    $data = json_encode($data);
    $data = json_decode($data);

    return $data;
  }
}