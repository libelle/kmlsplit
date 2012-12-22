#!/usr/bin/env php
<?php
define('ZULU', 'Y-m-d\TH:i:s\Z');
date_default_timezone_set('GMT');
/*
Sketchy KML splitter
*/
$opt_def = array(
    't' => 1800,
    'd' => 0.5,
    'm' => 5
);
if (count($argv) == 1) 
{
    echo "Sketchy KML splitter. Breaks a long track into smaller tracks based\n";
    echo "on time or distance between successive samples.\n";
    echo "Usage:\n";
    echo "kmlsplit.php [-t seconds for split] [-h hours for split] [-m max realistic speed] [-z time zone][-d distance to split] -f KML filespec\n";
    echo "defaults:\n";
    foreach ($opt_def as $k => $v) echo "-$k = $v\n";
}
$options = getopt('t::d::h::m::z::f:');
$spec = $options['f'];
if (isset($options['h'])) 
{
    $options['t'] = $options['h'] * 3600;
}
foreach ($opt_def as $k => $v) 
{
    if (!isset($options[$k])) 
    {
        $options[$k] = $v;
    }
}
$timezone = new DateTimeZone('GMT');
if (isset($options['z'])) 
{
    echo "Using localtime zone: ".$options['z']."\n";
    $timezone = new DateTimeZone($options['z']);
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
    $tp = analyzePoint($tt, $timezone);
    if ($tp !== false && $tp['speed'] < $options['m']) 
    {
        // valid, and less than realistic speed measure
        if ($prev != false) 
        {
            $timed = $tp['time']->getTimestamp() - $prev->getTimestamp();
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
                $dist = milesBetween($prevlat, $prevlon, $tp['lat'], $tp['lon']);
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
        $prevlat = $tp['lat'];
        $prevlon = $tp['lon'];
        $prev = $tp['time'];
        $tracks[$count]['points'][] = $tp;
    }
}
writeKML('0', $waypoints, $tracks, $options);
echo "Split data into " . count($tracks) . " tracks.\n";
exit;
function writeKML($count, $waypoints, $tracks, $options)
{
    $name = 'split_track_' . $count;
    $fh = fopen($name . '.kml', 'w') or die("Can't open file: " . $name . '.kml');
    fwrite($fh, kml_header($count,$options));
    fwrite($fh, kml_styles());
    fwrite($fh, kml_waypoints($waypoints));
    fwrite($fh, kml_tracks($tracks));
    fwrite($fh, kml_footer());
    fclose($fh);
}
function kml_header($count, $opts)
{
    $ret = "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . ">\n<kml xmlns=\"http://www.opengis.net/kml/2.2\"\n";
    $ret .= "\txmlns:gx=\"http://www.google.com/kml/ext/2.2\">\n" . "\t<Document>\n";
    $ret .= "\t<name>GPS device/split track</name>\n" . "\t<snippet>Converted " . date('Y-m-d H:i:s');
    $ret .= "\nUsing params:";
    foreach ($opts as $k=>$v)
    {
        $ret .= "-".$k."=".$v."\n";
    }
    $ret .= "</snippet>\n";
    return $ret;
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
function analyzePoint($d, $timezone) 
{
    $speed = false;
    $heading = false;
    $lookatlat = false;
    $lookatlon = false;
    $lookattilt = false;
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
        $local = new DateTime($d->TimeStamp->when);
        $local->setTimezone($timezone);
    }
    else
    {
        return false;
    }
    if (isset($d->LookAt)) 
    {
        $lookatlon = '' . $d->LookAt->longitude;
        $lookatlat = '' . $d->LookAt->latitude;
        $lookattilt = '' . $d->LookAt->tilt;
    }
    $matches = array();
    if (isset($d->description[0])) 
    {
        $pd = $d->description[0];
        if (preg_match('/Speed:\s([\d.]+)/', $pd, $matches)) 
        {
            $speed = $matches[1];
        }
        $matches = array();
        if (preg_match('/Heading:\s([\d.]+)/', $pd, $matches)) 
        {
            $heading = $matches[1];
        }
    }
    return array(
        'time' => $time,
        'localtime' => $local,
        'lat' => $lat,
        'lon' => $lon,
        'alt' => $alt,
        'speed' => $speed,
        'heading' => $heading,
        'la_lat' => $lookatlat,
        'la_lon' => $lookatlon,
        'la_tilt' => $lookattilt
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
    $lstart = false;
    $lstop = false;
    $minspeed = 100000;
    $maxspeed = 0;
    $points = 0;
    foreach ($track['points'] as $tp) 
    {
        if ($tp['speed'] !== false && $tp['speed'] < $minspeed) $minspeed = $tp['speed'];
        if ($tp['speed'] !== false && $tp['speed'] > $maxspeed) $maxspeed = $tp['speed'];
        if ($start === false) $start = $tp['time'];
        $stop = $tp['time'];
        if ($lstart === false) $lstart = $tp['localtime'];
        $lstop = $tp['localtime'];
        if ($prevlat !== false && $prevlon !== false) 
        {
            $dist+= milesBetween($prevlat, $prevlon, $tp['lat'], $tp['lon']);
            if ($prevalt < $tp['alt']) 
            {
                $gain+= $tp['alt'] - $prevalt;
            }
            else
            {
                $loss+= $tp['alt'] - $prevalt;
            }
            if ($tp['alt'] > $maxalt) 
            {
                $maxalt = $tp['alt'];
            }
            else if ($tp['alt'] < $minalt) 
            {
                $minalt = $tp['alt'];
            }
        }
        $prevlat = $tp['lat'];
        $prevlon = $tp['lon'];
        $prevalt = $tp['alt'];
        $points+= 1;
    }
    $dur = $stop->diff($start);
    return array(
        'points' => $points,
        'dist' => $dist,
        'gain' => $gain,
        'loss' => $loss,
        'minalt' => $minalt,
        'maxalt' => $maxalt,
        'maxspeed' => $maxspeed,
        'minspeed' => $minspeed,
        'start' => $start->format('Y-m-d H:i:s'),
        'stop' => $stop->format('Y-m-d H:i:s'),
        'lstart' => $lstart->format('Y-m-d H:i:s'),
        'lstop' => $lstop->format('Y-m-d H:i:s'),
        'duration' => $dur->format('%H hours, %i minutes, %s seconds')
    );
}
function kml_coord($pt, $delim = ',') 
{
    return $pt['lon'] . $delim . $pt['lat'] . $delim . $pt['alt'];
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
            $ret.= "<tr><td colspan=2>" . $det['lstart'] . "</td><tr>\n";
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
                $ret.= "\t\t\t\t\t<when>" . $tp['time']->format(ZULU) . "</when>\n";
            }
            foreach ($tt['points'] as $tp) 
            {
                $ret.= "\t\t\t\t\t<gx:coord>" . kml_coord($tp, ' ') . "</gx:coord>\n";
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
            $ret.= "<tr><td><b>Start Time</b> " . $det['start'] . "  UTC</td></tr>\n";
            $ret.= "<tr><td><b>End Time</b> " . $det['stop'] . " UTC</td></tr>\n";
            $ret.= "<tr><td><b>Start Time (local)</b> " . $det['lstart'] . "  </td></tr>\n";
            $ret.= "<tr><td><b>End Time (local)</b> " . $det['lstop'] . "</td></tr>\n";
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
                $ret.= "\t\t\t\t<Placemark>\n";
                $ret.= "\t\t\t\t<name>" . htmlentities($tname . '-' . $scount) . "</name>\n";
                $ret.= "\t\t\t\t<snippet/>\n";
                $ret.= "\t\t\t\t<description><![CDATA[\n";
                $ret.= "<table>\n";
                $ret.= "<tr><td>Longitude: " . $tp['lon'] . " </td></tr>\n";
                $ret.= "<tr><td>Latitude: " . $tp['lat'] . " </td></tr>\n";
                $ret.= "<tr><td>Altitude: " . $tp['alt'] . " ft </td></tr>\n";
                if ($tp['speed'] !== false) $ret.= "<tr><td>Speed: " . $tp['speed'] . " mph </td></tr>\n";
                if ($tp['heading'] !== false) $ret.= "<tr><td>Heading: " . $tp['heading'] . " </td></tr>\n";
                $ret.= "<tr><td>Time: " . $tp['time']->format(ZULU) . " UTC </td></tr>\n";
                $ret.= "<tr><td>Local Time: " . $tp['localtime']->format('Y-m-d H:i:s') . " </td></tr>\n";
                $ret.= "</table>\n";
                $ret.= "\t\t\t\t]]></description>\n";
                $ret.= "\t\t\t\t<LookAt>\n";
                $ret.= "\t\t\t\t\t<longitude>" . $tp['la_lon'] . "</longitude>\n";
                $ret.= "\t\t\t\t\t<latitude>" . $tp['la_lat'] . "</latitude>\n";
                $ret.= "\t\t\t\t\t<tilt>" . $tp['la_tilt'] . "</tilt>\n";
                $ret.= "\t\t\t\t</LookAt>\n";
                $ret.= "\t\t\t\t<TimeStamp><when>" . $tp['time']->format(ZULU) . "</when></TimeStamp>\n";
                $ret.= "\t\t\t\t<styleUrl>#track</styleUrl>\n";
                $ret.= "\t\t\t\t<Point>\n";
                $ret.= "\t\t\t\t\t<coordinates>" . kml_coord($tp) . "</coordinates>\n";
                $ret.= "\t\t\t\t</Point>\n";
                $ret.= "\t\t\t\t</Placemark>\n";
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
