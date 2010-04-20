<?php
// $Id$

/**
 * Class that defines the SolrSearch resource
 */
class SolrResource {
  /**
   * Performs a search against the Solr index.
   */
  public static function index($query='*:*', $page=0, $fields='', $params=array(), $sparams=array()) {
    $res = array(
      'items' => array(),
      'facets' => array(),
    );

    $rows = isset($params['rows']) ? $params['rows'] : 20;
    if ($rows > 200) {
      $rows = 200;
    }

    $sparams = $sparams + array(
      'start' => $page*$rows,
      'rows' => $rows,
      'qt' => 'standard',
    );
    $rows = $sparams['rows'];

    // Get field list
    if (empty($fields)) {
      $fields = array('nid', 'type', 'title');
    }
    else if (is_string($fields)) {
      $fields = split(',', $fields);
    }
    $sparams['fl'] = join($fields, ',');

    // Get facet params
    $facet_queries = array();
    $facets = array();
    if (isset($params['facets'])) {
      $facets = split(',', $params['facets']);
      $sparams['facet'] = 'true';
      $sparams['facet.sort'] = 'true';
      $sparams['facet.limit'] = !empty($params['facet_limit']) ? intval($params['facet_limit']) : 20;
      $sparams['facet.mincount'] = 1;
      $sparams['facet.field'] = $facets;

      if (isset($params['facet_queries'])) {
        $facet_queries = split(',', $params['facet_queries']);
        $sparams['facet.query'] = $facet_queries;
      }
    }

    // Validate sort parameter
    if (isset($params['sort']) && preg_match('/^([a-z0-9_]+ (asc|desc)(,)?)+$/i', $params['sort'])) {
      $sparams['sort'] = $params['sort'];
    }

    $solr = apachesolr_get_solr();
    $response = $solr->search($query, $sparams['start'], $sparams['rows'], $sparams, 'POST');
    $res['total'] = $response->response->numFound;
    $res['page_size'] = $sparams['rows'];
    if ($res['total']) {
      foreach ($response->response->docs as $doc) {
        $item = array();
        foreach ($doc->getFieldNames() as $field) {
          $item[$field] = $doc->getField($field);
          $item[$field] = $item[$field]['value'];
        }
        $res['items'][] = $item;
      }
    }
    if (isset($response->facet_counts->facet_fields)) {
      foreach ($response->facet_counts->facet_fields as $facet_field => $counts) {
        if (!empty($counts)) {
          $res['facets'][$facet_field] = get_object_vars($counts);

          switch ($facet_field) {
            case 'tid':
              self::getTermInfo($res['facets'][$facet_field]);
              break;
            // TODO: Maybe the facet describing process should be extensible?
          }
        }
      }
    }

    if (isset($response->facet_counts->facet_queries)) {
      foreach ($response->facet_counts->facet_queries as $query => $counts) {
        $res['query_facets'][$query] = $counts;
      }
    }

    return $res;
  }

  private static function getTermInfo(&$facets) {
    $tids = array_keys($facets);
    if (!empty($tids)) {
      $placeholders = join(array_fill(0, count($tids), '%d'), ', ');
      $res = db_query("SELECT tid, vid, name FROM {term_data} WHERE tid IN({$placeholders})", $tids);
      while($t = db_fetch_object($res)) {
        $facets[$t->tid] = array('name' => $t->name, 'vid' => $t->vid, 'count' => $facets[$t->tid]);
      }
    }
  }
}