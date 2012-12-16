#!/usr/bin/env php
<?php
/*
Sketchy KML splitter
*/
$options = array('t'=>1800,'d'=>0.5,'m'=>5);


if (count($argv) == 1) 
{
    echo "Sketchy KML splitter. Breaks a long track into smaller tracks based\n";
    echo "on time or distance between successive samples.\n";
    echo "Usage:\n";
    echo "kmlsplit.php [-t seconds for split] [-h hours for split] [-m max realistic speed] [-z time zone (delta from UTF)][-d distance to split] -f KML filespec\n";
    echo "defaults:\n";
    foreach ($options as $k=>$v)
    echo "-$k = $v\n";
}
$options = getopt('t::d::h::m::z::f:');
print_r($options);
$spec = $options['f'];
if (isset($options['h'])) 
{
    $options['t'] = $options['h'] * 3600;
}

if (!file_exists($spec)) 
{
    echo "Can't read $spec\n";
    exit;
}
$xml = simplexml_load_file($spec, 'SimpleXMLElement', LIBXML_NOCDATA);
if ($xml == FALSE) 
{
    echo "Failed to parse $spec\n";
    exit;
}
$waypoints = $xml->Document->Folder[0]->children();
if ($waypoints->name != 'Waypoints') 
{
    echo "Expected this to be the waypoints, but is " . $waypoints->name . "\n";
    exit;
}
$track = $xml->Document->Folder[1]->Folder->Folder->Placemark;
$prev = false;
$prevlat = false;
$prevlon = false;
$tracks = array();
$count = 0;
$tracks[$count]['points'] = array();
foreach ($track as $tt) 
{
    //print_r($tt);
    if (isset($tt->TimeStamp->when)) 
    {
        $ts = new DateTime($tt->TimeStamp->when);
        if (isset($tt->Point)) 
        {
            $pt = explode(",", $tt->Point->coordinates);
            $lon = $pt[0];
            $lat = $pt[1];
        }
        if ($prev != false) 
        {
            $timed = $ts->getTimestamp() - $prev->getTimestamp();
            if ($timed > $options['t']) 
            {
                // split
                $tracks[$count]['name'] = count($tracks[$count]['points']) . ' points, split due to time delta: ' . $timed . 's';
                $count+= 1;
                $prev = false;
                $tracks[$count]['points'] = array();
            }
            else if ($prevlat !== false && $prevlon !== false) 
            {
                // delta distance
                $dist = milesBetween($prevlat, $prevlon, $lat, $lon);
                if ($dist > $options['d']) 
                {
                    // split
                    $tracks[$count]['name'] = count($tracks[$count]['points']) . ' points, split due to gap of: ' . $dist . ' miles';
                    $count+= 1;
                    $prevlat = false;
                    $prevlon = false;
                    $tracks[$count]['points'] = array();
                }
            }
        }
        $prevlat = $lat;
        $prevlon = $lon;
        $prev = $ts;
        $tracks[$count]['points'][] = $tt;
    }
}
writeKML('0', $waypoints, $tracks);
function writeKML($count, $waypoints, $tracks) 
{
    $name = 'split_track_' . $count;
    $fh = fopen($name . '.kml', 'w') or die("Can't open file: " . $name . '.kml');
    fwrite($fh, kml_header($count));
    fwrite($fh, kml_styles());
    fwrite($fh, kml_waypoints($waypoints));
    fwrite($fh, kml_tracks($tracks));
    fwrite($fh, kml_footer());
    fclose($fh);
}
function kml_header($count) 
{
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . ">\n<kml xmlns=\"http://www.opengis.net/kml/2.2\"\n" . "\txmlns:gx=\"http://www.google.com/kml/ext/2.2\">\n" . "\t<Document>\n" . "\t<name>GPS device/split track</name>\n" . "\t<snippet>Converted " . date('Y-m-d H:i:s') . "</snippet>\n";
}
function kml_footer() 
{
    return "\t</Document>\n</kml>\n\n";
}
function kml_styles() 
{
    return file_get_contents('styles.xml');
}
function kml_waypoints($wp) 
{
    $ret = "\t<Folder>\n\t\t<name>Waypoints</name>\n";
    foreach ($wp as $p) 
    {
        if (isset($p->Point)) 
        {
            $ret.= "\t\t<Placemark>\n";
            $ret.= "\t\t\t<name>" . htmlentities($p->name) . "</name>\n";
            if (isset($p->description)) 
            {
                $ret.= "\t\t\t<description>" . htmlentities($p->description) . "</description>\n";
            }
            $ret.= "\t\t\t<styleUrl>" . $p->styleUrl . "</styleUrl>\n";
            $ret.= "\t\t\t<Point>\n";
            $ret.= "\t\t\t\t<coordinates>" . $p->Point->coordinates . "</coordinates>\n\t\t\t</Point>\n";
            $ret.= "\t\t</Placemark>\n";
        }
    }
    $ret.= "\t</Folder>\n";
    return $ret;
}

function analyzePoint($d)
{
    $speed = false;
    $heading = false;
    if (isset($d->Point)) 
    {
        $pt = explode(",", $d->Point->coordinates);
        $lon = $pt[0];
        $lat = $pt[1];
        $alt = $pt[2];
    }
    else
    {
        return false;
    }
    if (isset($d->TimeStamp->when))
    {
    $time = new DateTime($d->TimeStamp->when);
    }
    else
    {
        return false;
    }

    $matches = array();
    if (preg_match('/Speed:\s([\d.]+)/', $d, $matches)) 
    {
        $speed = $matches[1];
    }
    $matches = array();
    if (preg_match('/Heading:\s([\d.]+)/', $d, $matches)) 
    {
        $heading = $matches[1];
    }
    return array(
        'lat'=>$lat,
        'lon'=>$lon,
        'alt'=>$alt,
        'speed'=>$speed,
        'heading'=>$heading,
    );
}

function getHeadingAndSpeed($d) 
{
    $speed = false;
    $heading = false;
    $matches = array();
    if (preg_match('/Speed:\s([\d.]+)/', $d, $matches)) 
    {
        $speed = $matches[1];
    }
    $matches = array();
    if (preg_match('/Heading:\s([\d.]+)/', $d, $matches)) 
    {
        $heading = $matches[1];
    }
    return array(
        $speed,
        $heading
    );
}
function analyzeTrack($track) 
{
    $dist = 0;
    $gain = 0;
    $loss = 0;
    $minalt = 1000000;
    $maxalt = 0;
    $prevlat = false;
    $prevlon = false;
    $prevalt = false;
    $start = false;
    $stop = false;
    $minspeed = 100000;
    $maxspeed = 0;
    $points = 0;
    foreach ($track['points'] as $tp) 
    {
        if (isset($tp->description)) 
        {
            list($speed, $heading) = getHeadingAndSpeed($tp->description[0]);
            if ($speed !== false && $speed < $minspeed) $minspeed = $speed;
            if ($speed !== false && $speed > $maxspeed) $maxspeed = $speed;
        }
        if (isset($tp->Point)) 
        {
            if ($start === false) $start = $tp->TimeStamp->when;
            $stop = $tp->TimeStamp->when;
            $pt = explode(",", $tp->Point->coordinates);
            if ($prevlat !== false && $prevlon !== false) 
            {
                $dist+= milesBetween($prevlat, $prevlon, $pt[1], $pt[0]);
                if ($prevalt < $pt[2]) 
                {
                    $gain+= $pt[2] - $prevalt;
                }
                else
                {
                    $loss+= $pt[2] - $prevalt;
                }
                if ($pt[2] > $maxalt) 
                {
                    $maxalt = $pt[2];
                }
                else if ($pt[2] < $minalt) 
                {
                    $minalt = $pt[2];
                }
            }
            $prevlat = $pt[1];
            $prevlon = $pt[0];
            $prevalt = $pt[2];
            $points+= 1;
        }
    }
    $startdt = new DateTime($start);
    $stopdt = new DateTime($stop);
    $dur = $stopdt->diff($startdt);
    return array(
        'points' => $points,
        'dist' => $dist,
        'gain' => $gain,
        'loss' => $loss,
        'minalt' => $minalt,
        'maxalt' => $maxalt,
        'maxspeed' => $maxspeed,
        'minspeed' => $minspeed,
        'start' => $start,
        'stop' => $stop,
        'duration' => $dur->format('%H hours, %i minutes, %s seconds')
    );
}
function kml_tracks($tracks) 
{
    $ret = "\t<Folder>\n\t\t<name>Tracks</name>\n";
    $good = true;
    $trackno = 0;
    foreach ($tracks as $tt) 
    {
        $det = analyzeTrack($tt);
        if ($det['points'] > 2) 
        {
            $tname = 'TRACK' . $trackno;
            $ret.= "\t\t\t<Placemark>\n";
            $ret.= "\t\t\t\t<name>" . htmlentities($tname) . "</name>\n";
            $ret.= "\t\t\t<description>\n";
            $ret.= "<![CDATA[<table>\n";
            $ret.= "<tr><td colspan=2>" . $det['start'] . "</td><tr>\n";
            $ret.= "<tr><td colspan=2>" . htmlentities($tt['name']) . "</td><tr>\n";
            $ret.= "<tr><td><b>Data Points</b> " . $det['points'] . "</td></tr>\n";
            $ret.= "<tr><td><b>Distance</b> " . $det['dist'] . " mi </td></tr>\n";
            $ret.= "<tr><td><b>Duration</b> " . $det['duration'] . "</td></tr>\n";
            $ret.= "</table>]]>\n";
            $ret.= "\t\t\t</description>\n";
            $ret.= "\t\t\t\t<styleUrl>#multiTrack</styleUrl>\n";
            $ret.= "\t\t\t\t<gx:Track>\n";
            foreach ($tt['points'] as $tp) 
            {
                $ret.= "\t\t\t\t\t<when>" . $tp->TimeStamp->when . "</when>\n";
            }
            foreach ($tt['points'] as $tp) 
            {
                $ret.= "\t\t\t\t\t<gx:coord>" . $tp->Point->coordinates . "</gx:coord>\n";
            }
            $ret.= "\t\t\t\t</gx:Track>\n\t\t\t</Placemark>\n";
            $ret.= "\t\t<Folder>\n";
            $ret.= "\t\t\t<name>" . htmlentities($tname) . "</name>\n";
            $ret.= "\t\t\t<snippet/>\n";
            $ret.= "\t\t\t<description>\n";
            $ret.= "<![CDATA[<table>\n";
            $ret.= "<tr><td colspan=2>" . htmlentities($tt['name']) . "</td><tr>\n";
            $ret.= "<tr><td><b>Distance</b> " . $det['dist'] . " mi </td></tr>\n";
            $ret.= "<tr><td><b>Min Alt</b> " . $det['minalt'] . " ft </td></tr>\n";
            $ret.= "<tr><td><b>Max Alt</b> " . $det['maxalt'] . " ft </td></tr>\n";
            $ret.= "<tr><td><b>Cumul. Alt. Gain</b> " . $det['gain'] . " ft </td></tr>\n";
            $ret.= "<tr><td><b>Cumul. Alt. Loss</b> " . $det['loss'] . " ft </td></tr>\n";
            $ret.= "<tr><td><b>Max Speed</b> " . $det['maxspeed'] . " mph </td></tr>\n";
            $ret.= "<tr><td><b>Min Speed</b> " . $det['minspeed'] . " mph </td></tr>\n";
            $ret.= "<tr><td><b>Start Time</b> " . $det['start'] . "  </td></tr>\n";
            $ret.= "<tr><td><b>End Time</b> " . $det['stop'] . "</td></tr>\n";
            $ret.= "<tr><td><b>Data Points</b> " . $det['points'] . "</td></tr>\n";
            $ret.= "<tr><td><b>Duration</b> " . $det['duration'] . "</td></tr>\n";
            $ret.= "</table>]]>\n";
            $ret.= "\t\t\t</description>\n";
            $ret.= "\t\t\t<TimeSpan>\n";
            $ret.= "\t\t\t\t<begin>" . $det['start'] . "</begin>\n";
            $ret.= "\t\t\t\t<end>" . $det['stop'] . "</end>\n";
            $ret.= "\t\t\t</TimeSpan>\n";
            $ret.= "\t\t\t<Folder>\n";
            $ret.= "\t\t\t\t<name>Points</name>\n";
            $scount = 0;
            foreach ($tt['points'] as $tp) 
            {
                if (isset($tp->Point)) 
                {
                    $ret.= "\t\t\t\t<Placemark>\n";
                    $ret.= "\t\t\t\t<name>" . htmlentities($tname . '-' . $scount) . "</name>\n";
                    $ret.= "\t\t\t\t<snippet/>\n";
                    list($speed, $heading) = getHeadingAndSpeed($tp->description[0]);
                    $ret.= "\t\t\t\t<description><![CDATA[\n";
                    $ret.= "<table>\n";
                    $pt = explode(",", $tp->Point->coordinates);
                    $ret.= "<tr><td>Longitude: " . $pt[0] . " </td></tr>\n";
                    $ret.= "<tr><td>Latitude: " . $pt[1] . " </td></tr>\n";
                    $ret.= "<tr><td>Altitude: " . $pt[2] . " ft </td></tr>\n";
                    if ($speed !== false) $ret.= "<tr><td>Speed: " . $speed . " mph </td></tr>\n";
                    if ($heading !== false) $ret.= "<tr><td>Heading: " . $heading . " </td></tr>\n";
                    $ret.= "<tr><td>Time: " . $tp->TimeStamp->when . " </td></tr>\n";
                    $ret.= "</table>\n";
                    $ret.= "\t\t\t\t]]></description>\n";
                    $ret.= "\t\t\t\t<LookAt>\n";
                    $ret.= "\t\t\t\t\t<longitude>" . $tp->LookAt->longitude . "</longitude>\n";
                    $ret.= "\t\t\t\t\t<latitude>" . $tp->LookAt->latitude . "</latitude>\n";
                    $ret.= "\t\t\t\t\t<tilt>" . $tp->LookAt->tile . "</tilt>\n";
                    $ret.= "\t\t\t\t</LookAt>\n";
                    $ret.= "\t\t\t\t<TimeStamp><when>" . $tp->TimeStamp->when . "</when></TimeStamp>\n";
                    $ret.= "\t\t\t\t<styleUrl>#track</styleUrl>\n";
                    $ret.= "\t\t\t\t<Point>\n";
                    $ret.= "\t\t\t\t\t<coordinates>" . $tp->Point->coordinates . "</coordinates>\n";
                    $ret.= "\t\t\t\t</Point>\n";
                    $ret.= "\t\t\t\t</Placemark>\n";
                }
                $scount+= 1;
            }
            $trackno+= 1;
            $ret.= "</Folder>\n";
            $ret.= "</Folder>\n";
        }
    }
    $ret.= "\t</Folder>\n";
    return $ret;
}
function milesBetween($lat1, $lng1, $lat2, $lng2) 
{
    $pi80 = M_PI / 180;
    $lat1*= $pi80;
    $lng1*= $pi80;
    $lat2*= $pi80;
    $lng2*= $pi80;
    $r = 6372.797;
    $dlat = $lat2 - $lat1;
    $dlng = $lng2 - $lng1;
    $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlng / 2) * sin($dlng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $km = $r * $c;
    return ($km * 0.621371192);
}
