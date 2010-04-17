<?php

/*
OpenGraphMap processing
Copyright (C) 2010 Pete Warden <pete@petewarden.com>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once('bucketgrid.php');

class OSMWays
{
    public $nodes = array();
    public $ways = array();
    public $bounding_box = null;
    public $current_id = 1;
    public $current_way = null;
    public $previous_vertex_id = null;
    public $duplicate_epsilon = 0.000001;
    public $bucket_grid = null;
    public $tag_map = null;
    
    public function __construct($id_start=null)
    {
        $this->bucket_grid = new BucketGrid(0.0001);
        $this->tag_map = array();
            
        if (isset($id_start))
            $this->current_id = $id_start;
    }
    
    public function add_node($lat, $lon, $node_id=null)
    {
        if (!isset($node_id))
        {
            $node_id = $this->current_id;
            $this->current_id += 1;
        }
        
        $node_data = array('lat' => $lat, 'lon' => $lon);
        
        $this->nodes[$node_id] = $node_data;
        
        $this->bucket_grid->insert_point($lat, $lon, array('id' => $node_id));
        
        return $node_id;
    }

    public function set_bounding_box($top_lat, $left_lon, $bottom_lat, $right_lat)
    {        
        $this->bounding_box = array($top_lat, $left_lon, $bottom_lat, $right_lat); 
    }
    
    public function begin_way($way_id=null)
    {
        if (!isset($way_id))
        {
            $way_id = $this->current_id;
            $this->current_id += 1;
        }
        
        $this->ways[$way_id] = array(
            'bounding_box' => array(),
            'tags' => array(),
            'nds' => array(),
        );
        
        $this->current_way = $way_id;
        $this->previous_vertex_id = null;
        
        return $way_id;
    }
    
    public function end_way()
    {
        $way = &$this->ways[$this->current_way];

        $nds = $way['nds'];
        $nds_count = count($nds);
        if ($nds_count===0)
        {
            $is_closed = false;
        }
        else
        {
            $first_vertex_id = $nds[0];
            $last_vertex_id = $nds[$nds_count-1];
            $is_closed = ($first_vertex_id===$last_vertex_id);
        }
        
        $way['is_closed'] = $is_closed;
    
        $this->current_way = null;
    }
    
    private function check_is_inside_way()
    {
        if (!isset($this->current_way))
            die('Function called outside begin_way()/end_way() pair');
    }
        
    public function add_tag($key, $value)
    {
        $this->check_is_inside_way();
        
        $way = &$this->ways[$this->current_way];
        
        $way['tags'][$key] = $value;
        
        if (!isset($this->tag_map[$key]))
            $this->tag_map[$key] = array();
            
        if (!isset($this->tag_map[$key][$value]))
            $this->tag_map[$key][$value] = array();
            
        $this->tag_map[$key][$value][] = $this->current_way;
    }
    
    public function add_vertex($lat, $lon)
    {
        $this->check_is_inside_way();

        $duplicate_list = $this->bucket_grid->find_points_near(
            $lat, 
            $lon, 
            $this->duplicate_epsilon
        );
        
        $node_id = null;
        if (!empty($duplicate_list))
        {
            $duplicate_entry = $duplicate_list[0];
            $node_id = $duplicate_entry['data']['id'];
        }
        
        if (!isset($node_id))
        {
            $node_id = $this->add_node($lat, $lon);
        }
        
        // Make sure this isn't just a duplicate of the last vertex
        if (!isset($this->previous_vertex_id)||
            ($this->previous_vertex_id!==$node_id))
        {
            $this->add_vertex_index($node_id);
            $this->previous_vertex_id = $node_id;
        }
    }
    
    public function add_vertex_index($node_id)
    {
        $this->check_is_inside_way();

        $way = &$this->ways[$this->current_way];
        
        $way['nds'][] = $node_id;
    }
    
    public function serialize_to_xml()
    {
        $result = '<';
        $result .= '?xml version=\'1.0\' encoding=\'UTF-8\'?';
        $result .= '>';
        $result .= "\n";
        $result .= '<osm version="0.6" generator="mapfileprocess">';
        $result .= "\n";
        
        if (!empty($this->bounding_box))
        {
            $result .= '  ';
            $result .= '<bound box="';
            $result .= implode(',', $this->bounding_box);
            $result .= '"/>';
            $result .= "\n";
        }
        
        foreach ($this->nodes as $node_id => $node_data)
        {
            $node_lat = $node_data['lat'];
            $node_lon = $node_data['lon'];
            
            $result .= '  ';
            $result .= '<node id="';
            $result .= $node_id;
            $result .= '" lat="';
            $result .= $node_lat;
            $result .= '" lon="';
            $result .= $node_lon;
            $result .= '"/>';
            $result .= "\n";
        }
        
        foreach ($this->ways as $way_id => $way_data)
        {
            $result .= '  ';
            $result .= '<way id="';
            $result .= $way_id;
            $result .= '">';
            $result .= "\n";

            foreach ($way_data['nds'] as $node_id)
            {
                $result .= '    ';
                $result .= '<nd ref="';
                $result .= $node_id;
                $result .= '"/>';
                $result .= "\n";
            }

            foreach ($way_data['tags'] as $key => $value)
            {
                $result .= '    ';
                $result .= '<tag k="';
                $result .= $key;
                $result .= '" v="';
                $result .= $value;
                $result .= '"/>';
                $result .= "\n";
            }
            
            $result .= '  ';
            $result .= '</way>';
            $result .= "\n";
        }
        
        $result .= '</osm>';
        $result .= "\n";
        
        return $result;
    }
    
    public function deserialize_from_xml($xml_string)
    {
        $xml_data = simplexml_load_string($xml_string);
        
        foreach ($xml_data->children() as $top_child)
        {
            $name = strtolower($top_child->getName());
            
            if ($name=='node')
            {
                $node_id = (string)($top_child['id']);
                $node_lat = (float)($top_child['lat']);
                $node_lon = (float)($top_child['lon']);
                
                $this->add_node($node_lat, $node_lon, $node_id);
            }
            else if ($name=='way')
            {
                $way_id = (string)($top_child['id']);
                
                $this->begin_way($way_id);
                
                foreach ($top_child->children() as $way_child)
                {
                    $child_name = strtolower($way_child->getName());
                    if ($child_name=='nd')
                    {
                        $ref = (string)($way_child['ref']);
                        $this->add_vertex_index($ref);
                    }
                    else if ($child_name=='tag')
                    {
                        $key = (string)($way_child['k']);
                        $value = (string)($way_child['v']);
                        $this->add_tag($key, $value);
                    }
                    else
                    {
                        error_log('Unknown way tag '.$child_name.' encountered, skipping');
                    }
                }
                
                $this->end_way($way_id);
            }
            else if ($name=='bound')
            {
                $box_string = (string)($top_child['box']);
                $box_list = explode(',', $box_string);
                $this->set_bounding_box($box_list[0], $box_list[1], $box_list[2], $box_list[3]);
            }
            else
            {
                error_log('Unknown tag '.$name.' encountered, skipping');
            }
        
        }
    
    }
    
    public function get_ways_matching_keys($searchkeys)
    {
        $matching_ids = null;
        
        foreach ($searchkeys as $key => $value)
        {
            if (!isset($this->tag_map[$key]))
                continue;

            if (!isset($this->tag_map[$key][$value]))
                continue;                
                
            $current_ids = $this->tag_map[$key][$value];
            
            if (!isset($matching_ids))
            {
                $matching_ids = array_count_values($current_ids);
            }
            else
            {   
                $intersection_ids = array();
                foreach ($current_ids as $way_id)
                {
                    if (isset($matching_ids[$way_id]))
                        $intersection_ids[] = $way_id;
                }
                
                $matching_ids = array_count_values($intersection_ids);
            }
            
        }
        
        if (empty($matching_ids))
            return array();
            
        $result = array();
        
        foreach ($matching_ids as $way_id => $count)
        {
            $result[] = $this->ways[$way_id];
        }
        
        return $result;
    }
    
}

?>