<?php

/* searchParser: Class to parse search term strings.
 * version 0.5.0
 *
 * Marty Vance
 * 15 Oct 2010
 *
 * License: GNU GPL v2: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 */

class searchParser {

    // these are for localization
    private $quote_chars = array();
    private $apos_chars = array();
    private $and_opers = array();
    private $or_opers = array();
    private $bool_opers = array();

    private $bool_re = '';

    // the regular expression used to extract terms from the string
    private $regex = '';

    private $search_string = '';

    private $terms = array();
    private $terms_count = null;
    private $phrases = array();
    private $phrase_count = 0;

    private $indent = -1;

    /*
     * $q: array of quote characters
     * $a: array of apostrophe characters
     * $n: array of boolean AND operators
     * $o: array of boolean OR operators
     */

    public function __construct($q, $a, $n, $o) {
        if (!is_array($q) || count($q) < 1) {
            // fail
            die('bad quotes');
        }
        if (!is_array($a) || count($a) < 1) {
            // fail
            die('bad apos');
        }
        if (!is_array($n) || count($n) < 1) {
            // fail
            die('bad AND bools');
        }
        if (!is_array($o) || count($o) < 1) {
            // fail
            die('bad OR bools');
        }
        $this->buildQuotes($q);
        $this->buildApos($a);
        $this->buildBool($n, $o);
        $this->unary_opers = array('-','+');

        $this->buildRegex();
    }

    public function __get($n) {
        if (isset($this->$n)) {
            return $this->$n;
        }
    }

    private function buildQuotes($q) {
        foreach ($q as $d) {
            $d = trim($d);
            if (strlen($d) != 1) {
                continue;
            }
            else {
                $this->quote_chars[] = $d;
            }
        }
    }

    private function buildApos($a) {
        foreach ($a as $d) {
            $d = trim($d);
            if (strlen($d) != 1) {
                continue;
            }
            else {
                $this->apos_chars[] = $d;
            }
        }
    }

    private function buildBool($n, $o) {
        foreach ($n as $d) {
            $d = trim(strtoupper($d));
            if (strlen($d) == 0 || preg_match('/^\w+$/', $d) != 1 || in_array($d, $this->bool_opers)) {
                continue;
            }
            else {
                $this->and_opers[] = ($d);
                $this->bool_opers[] = ($d);
            }
        }
        foreach ($o as $d) {
            $d = trim(strtoupper($d));
            if (strlen($d) == 0 || preg_match('/^\w+$/', $d) != 1 || in_array($d, $this->bool_opers)) {
                continue;
            }
            else {
                $this->or_opers[] = ($d);
                $this->bool_opers[] = ($d);
            }
        }
        $this->bool_re = implode('|', $this->bool_opers);
    }

    private function buildRegex() {
        /*
        raw: '((?<=^|\s)(?:[\+\-]?"[^"]+"(?=\s|$)|[\+\-]?'[^']+'(?=\s|$)|[\+\-]?\S+|AND|OR)(?=$|\s))'
        (
            (?<=^|\s)(?:
                (?:\w+:)?
                [\+\-]?"[^"]+"(?=\s|$)|  // instance for each quote char
                [\+\-]?'[^']+'(?=\s|$)|  // instance for each apos char
                [\+\-]?\S+|
                AND|OR            // list of all given bool operators
            )(?=$|\s)
        )
        */
        $r = '((?<=^|\s)(?:(?:\w+:)?';
        foreach ($this->quote_chars as $v) {
            $r .= '[\+\-]?' . $v . '[^' . $v . ']+' . $v . '(?=\s|$)|';
        }
        foreach ($this->apos_chars as $v) {
            $r .= '[\+\-]?' . $v . '[^' . $v . ']+' . $v . '(?=\s|$)|';
        }
        $r .= '[\+\-]?\S+|' . $this->bool_re . ')(?=$|\s))';

        $this->regex = $r;
    }

    private function parse() {
        if ($this->search_string == '') {
            return false;
        }
        $terms = $this->terms;
        $ob_terms = array();
        $matches = null;
        preg_match_all($this->regex, $this->search_string, $matches, PREG_PATTERN_ORDER);

        $current_index = 0;
        $prev_logic = null;
        $current_unary = null;

        $phrase_operator = false;
        if (!is_array($matches[0]) || count($matches[0]) < 1) {
            return false;
        }

        $terms = $matches[0];
        $terms_count = count($terms);

        reset($terms);
        $current_index = -1;

        while ($s = array_shift($terms)) {
            // loop through the segments

            // step 1: ignore leading logical operators
            // (with optional bogus unary operators)
            if (!isset($have_phrase) && preg_match('/^[\+\-]*(?:' . $this->bool_re . ')$/i', $s)) {
                continue;
            }

            // step 2: strip down unary operators to the last one
            $s = preg_replace('/^[\+\-]*([\+\-].*)/', '\1', $s);

            $current_index++;
            $ob_terms[] = $s;

            $u = substr($s, 0, 1);
            if ($u == '+' || $u == '-') {
                $s = substr($s, 1);
            }
            
            // step 3: add logical operator
            if (in_array(strtoupper($s), $this->bool_opers)) {
                // this term is a logical operator
                // UC it
                array_pop($ob_terms);
                $ob_terms[] = strtoupper($s);
                if ($prev_logic) {
                    // previous term was a logical operator, junk it
                    array_pop($this->phrases);
                    array_pop($ob_terms);
                    array_pop($ob_terms);
                    $ob_terms[] = strtoupper($s);
                }
                $this->phrases[$current_index] = strtoupper($s);
                $phrase_operator = true;
                $prev_logic = true;
                continue;
            }

            // segment should be clean now
            $have_phrase = true;

            // consecutive non-bool terms imply an AND operator
            if (isset($have_phrase) && ($prev_logic !== true) && $current_index > 0) {
                $p2 = array_pop($ob_terms);

                $this->phrases[$current_index] = $this->and_opers[0];
                $ob_terms[] = $this->and_opers[0];

                $current_index++;
                $ob_terms[] = $p2;
            }

            // now we know we have an actual term
            $t = new StdClass();
            $prev_logic = false;

            // unary
            if ($u == '+' || $u == '-') {
                // unary operator, handle it
                if ($u == '-') {
                    $t->unary = $u;
                }
            }
            // field
            if (preg_match('/^\w+:/', $s)) {
                list($f, $s) = explode(':', $s, 2);
                $t->field = $f;
            }
            // quotes
            $a = substr($s, 0, 1);
            $o = substr($s, -1);
            if (in_array($a, $this->quote_chars) and in_array($o, $this->quote_chars) and $a == $o) {
                // matching quotes, handle it
                $s = substr($s, 1, -1);
            }
            $t->term = $s;
            $this->phrases[$current_index] = $t;

        }

        // junk trailing bool oper (should only be one, we junked consecutives above)
        if (isset($this->phrases[$current_index]) && 
            is_string($this->phrases[$current_index]) && 
            in_array(strtoupper($this->phrases[$current_index]), $this->bool_opers)
        ) {
            array_pop($this->phrases);
            array_pop($ob_terms);
        }

        $this->terms = $ob_terms;
        $this->terms_count = count($ob_terms);
            
        // we now have a clean sequence, add structure to it
        list($phrases, $junk) = $this->construct();
        return true;
    }

    public function setString($s) {
        if (is_string($s) && strlen($s) > 0) {
            $this->search_string = $s;
            $this->phrases = array();
            $this->parse();
            return true;
        }
        else {
            return false;
        }
    }

    private function construct() {
        if (func_num_args() > 0) {
            $phrase = func_get_arg(0);
            if (!is_array($phrase)) {
                return false;
            }
            if (func_num_args() > 1) {
                $depth = func_get_arg(1);
                if (!is_array($depth)) {
                    return false;
                }
            }
        }
        if (!isset($phrase)) {
            $phrase = $this->phrases;
        }
        if (!isset($depth)) {
            $depth = array(0);
        }
        $new_phrase = array();
        $prev_op = null;
        $pindex = 0;
        $pcount = count($phrase);
        for ($i = 0; $i < $pcount; $i++) {
            $p = $phrase[$i];
            if (is_string($p)) {
                // bool operator
                $new_phrase[] = $p;
                $prev_op = $p;
                $depth[array_pop(array_keys($depth))]++;
            }
            else if (is_object($p)) {
                // term
                $new_phrase[] = $p;
                $depth[array_pop(array_keys($depth))]++;
            }
            else {
            }
            $pindex++;
        }
        return array($new_phrase, $depth);
    }

    public function dumpPhrases() {
        $this->indent++;
        if (func_num_args() > 0) {
            $phrases = func_get_arg(0);
            if (!is_array($phrases)) {
                return false;
            }
        }
        else {
            $phrases = $this->phrases;
        }
        $lead = str_repeat('    ', $this->indent);
        $i = array(0);
        $o = '';
        foreach ($phrases as $p) {
            if (is_string($p)) {
                // bool operator
                $o .= $lead . "[operator] => $p\n";
            }
            else if (is_array($p)) {
                // phrase
                $o .= $lead . "[phrase] => {\n";
                $o .= $this->dumpPhrases($p);
                $o .= $lead . "}\n";
            }
            else if (is_object($p)) {
                // term
                $o .= $lead . "[term] ";
                $o .= (isset($p->unary) ? 'NOT ' : '');
                $o .= (isset($p->field) ? '[' . $p->field . '] ' : '');
                $o .= '=> ' . $p->term . "\n";
            }
            else {
                $o .= "wtf\n";
            }
        }
        $this->indent--;
        return $o;
    }

    /*
     * Transforms the parsed search string into a SQL WHERE clause
     * @arg $fields: array of table field names, aliases, etc suitable for use in the query
     * @return string
     */

    public function getWhere($fields) {

        return true;
    }
}
?>
