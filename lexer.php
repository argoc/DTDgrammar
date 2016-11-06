<?php

/*
parseDTD Copyright 2016, Amelia Garripoli
http://faculty.olympic.edu/agarripoli
Free to use under the MIT license.
http://www.opensource.org/licenses/mit-license.php
11/6/2016
*/


// Lexer : lexes an input coming in
// as an array of lines, one token at a time.
//
// returns null when end of stream
// moves along stream one token at a time
// tokens returned as assoc. arrays.

// ideas from 
// http://nitschinger.at/Writing-a-simple-lexer-in-PHP/
// http://www.codediesel.com/php/building-a-simple-parser-and-lexer-in-php/

// tokens from W3's spec
// https://www.w3.org/TR/REC-xml/#NT-intSubset

// limitations:
// * only ASCII in identifiers
// * minimal PEREF and PI support
// * not detecting newlines as whitespace (ignoring them, but
//   lexing separate lines separately)

// tokenizes against the DTD BNF.
// handles PEREF substitutions when it knows the PEREF value
// (parser only hands it internal PEREFs)
// could argue that the token needs to be an object, not an
// associative array. maybe that will be addressed ...
class Lexer {
    
    // who doesn't like a few constants?
    // you can argue the merits of doing this with strings,
    // and say they should be an index lookup from ints to strings
    // but this works, and given the small size of DTDs
    // relative to the modern PC, it runs fine.
    // chose values with an eye to error messages ...
    // let's talk localization another day.
    const ANY        = "ANY";
    const ATTLIST    = "!ATTLIST";
    const CDATA      = "CDATA";
    const COMMA      = ",";
    const COMMENT    = "<!--";
    const ELEMENT    = "!ELEMENT";
    const ENDCOMMENT = "-->";
    const ENDPI      = "?>";
    const ENDRULE    = ">";
    const ENTITY     = "!ENTITY";
    const ENTITIES   = "ENTITY/ENTITIES";
    const FIXED      = "#FIXED";
    const ID         = "ID";
    const IDREFS     = "IDREF/IDREFS";
    const IMPLIED    = "#IMPLIED";
    const LITERAL    = "string";
    const LPAREN     = "(";
    const MULTIPLE   = "+, *, or ?";
    const NAME       = "name";
    const NDATA      = "NDATA";
    const NMTOKENS   = "NMTOKEN/NMTOKENS";
    const NOTATION   = "!NOTATION/NOTATION";
    const EMPTIER    = "EMPTY";
    const PCDATA     = "#PCDATA";
    const PERCENT    = "%";
    const PEREF      = "PARAMETER ENTITY REF";
    const PIPE       = "|";
    const PROCINST   = "<?";
    const PUBLICLY   = "PUBLIC";
    const REQUIRED   = "#REQUIRED";
    const RPAREN     = ")";
    const SPACE      = "whitespace";
    const SYSTEM     = "SYSTEM";
    const NMTOKEN    = "token";
    const INCOMMENT  = "..in comment..";

    protected static $_perefs = array();
    
    // lex engine when inside a comment
    protected static $_incomment = array(
        "/^([^\-])/" => self::INCOMMENT,
        "/^(\-[^\-])/" => self::INCOMMENT,
        "/^(\-\-\>)/" => self::ENDCOMMENT
    );

    // lex engine when not in a comment
    protected static $_terminals = array(
        "/^(<!--)/" => self::COMMENT,
        "/^(-->)/" => self::ENDCOMMENT, // left in for better errors
        "/^(>)/" => self::ENDRULE,
        "/^(<!ELEMENT)/" => self::ELEMENT,
        "/^(<!ATTLIST)/" => self::ATTLIST,
        "/^(<!ENTITY)/" => self::ENTITY,
        "/^(<!NOTATION)/" => self::NOTATION,
        "/^(<\?)/" => self::PROCINST,
        "/^(\?>)/" => self::ENDPI,
        "/^(ANY)/" => self::ANY,
        "/^(CDATA)/" => self::CDATA,
        "/^(EMPTY)/" => self::EMPTIER,
        "/^(ENTIT(IES|Y))/" => self::ENTITIES,
        "/^(IDREF[S]?)/" => self::IDREFS,
        "/^(ID)/" => self::ID,
        "/^(NDATA)/" => self::NDATA,
        "/^(NMTOKEN[S]?)/" => self::NMTOKENS,
        "/^(NOTATION)/" => self::NOTATION,
        "/^(PUBLIC)/" => self::PUBLICLY,
        "/^(SYSTEM)/" => self::SYSTEM,
        "/^(#IMPLIED)/" => self::IMPLIED,
        "/^(#REQUIRED)/" => self::REQUIRED,
        "/^(#FIXED)/" => self::FIXED,
        "/^(#PCDATA)/" => self::PCDATA,
        "/^([\x20\x09\x0D\x0A]+)/" => self::SPACE,
        "/^(\()/" => self::LPAREN,
        "/^(\))/" => self::RPAREN,
        "/^(,)/" => self::COMMA,
        "/^(\|)/" => self::PIPE,
        "/^([\*\+\?])/" => self::MULTIPLE,
        "/^([A-Za-z\_\:]([A-Za-z0-9\.\:\_-])*)/" => self::NAME,
        "/^([A-Za-z0-9.:_-]+)/" => self::NMTOKEN,
        // check these in context after tokenizing a LITERAL...
        //"/^(\&[A-Za-z\_\:][A-Za-z0-9\.\:\-\_]*;)/" => "ENTITY REF",
        //"/^(\&#[0-9]+;)/" => "CHAR REF",
        //"/^(\&#x[0-9a-fA-F]+;)/" => "CHAR REF",
        "/^(\%[A-Za-z\_\:][A-Za-z0-9\.\:\-\_]*;)/" => self::PEREF,
        // check these IN CONTEXT after tokenizing a LITERAL...
        //"/^(\"[a-zA-Z0-9\x20\x0D\x0A\-\'\(\)\+\,\.\/\:\=\?\;\!\*\#\@\$\_\%]*\")/" => Lexer::PUBLICID,
        //"/^(\'[a-zA-Z0-9\x20\x0D\x0A\-\(\)\+\,\.\/\:\=\?\;\!\*\#\@\$\_\%]*\')/" => Lexer::PUBLICID,
        "/^(\"[^\"]*\")/" => self::LITERAL,
        "/^(\'[^\']*\')/" => self::LITERAL,
        "/^(\%)/" => self::PERCENT,
        "/^(=)/" => "UNKNOWN", // so it won't swallow PI atts
        "/^([^\s]+)/" => "UNKNOWN", // all the leftovers
    );
 
    protected $subs;     // subs perefs
    protected $input;     // input string
    protected $indent;  // indent for linenums
    protected $line;      // current line num
    protected $currline;      // current line
    protected $offset;    // current offset in line
    protected $incomment; // lex state -- in a comment or not

    // hand it an array of lines ...
    public function Lexer($input, $subs) {
        $this->input = $input;
        $this->incomment = false;
        $this->subs = $subs;
        $this->line = 0;
        $this->indent = strlen((string) count($input));
        $this->offset = 0;
        $this->currline = $input[0];
        $this->showline();
    }
 
    // returns the next token from the input stream.
    // when there are no more, it returns a null
    // throws an exception if there is not a valid token
    // in the current stream based on the current lex lookup table
    public function nextToken() {
        // end of current line, move to next
        while ($this->offset >= strlen($this->currline)) {
            $this->line++;
            if ($this->line >= count($this->input)) {
                return null; //all done!
            }
            $this->currline = $this->input[$this->line];
            $this->showline();
            $this->offset=0;
        }
        // see what the next token is
        $token = $this->_match();
        if($token === false) {
            $this->incomment=false; // reset on error
            throw new LogicException(
                "Unable to parse line " . ($this->line+1) . 
                " at position " . ($this->offset) . 
                " [ " . $this->currline . " ] .");
        }
        
        // TODO:
        // if token is a PEREF have to put it into the string and
        // start over from where it is located...
        // note if there is a cycle we will end up overflowing
        // (let memory detect cycles, this is free code)
        // if just tokenizing, skip this...(subs false then)
        if ($this->subs && $token['token'] == Lexer::PEREF) {

            $refoffset = $this->offset + strlen($token['match']);
            
            // strip the & and the ;
            $petok = substr($token['match'],1,-1);

            if (!isset(static::$_perefs[$petok])) {
                // doesn't exist, so don't do the subs, see
                // if it's in a legal spot in the file...
                $this->offset = $refoffset;
                return $token;    
            }

            // substitute and try again...
            // stuff the subs in at this position, replacing the peref. 
            $firstpart = substr($this->currline,0,$this->offset);
            $lastpart = substr($this->currline,$refoffset);

            $this->currline = $firstpart.static::$_perefs[$petok].$lastpart;
            
            $this->showline();

            // and try again.
            $token = $this->nextToken();
        }
        
        //move to start of next token
        $this->offset += strlen($token['match']);
        
        return $token;
    }

    // at our core, we try to match the current offset
    // in the current line against the lexing table
    // in comments it's a different lexing table
    // we take as much of the string as we need to
    // and we stop at the first match
    // matches CANNOT span lines
    protected function _match() {
        // look at the current offset
        $string = substr($this->currline, $this->offset);
        // match against the regex'd tokens
        if ($this->incomment) {
            foreach (static::$_incomment as $pattern => $name) {
                if (preg_match($pattern, $string, $matches)) {
                    if ($name == Lexer::ENDCOMMENT) {
                        $this->incomment=false;
                    }
                    // stops at first match
                    return array(
                        'match' => $matches[1],
                        'token' => $name,
                        'line' => $this->line+1,
                        'offset' => $this->offset
                    );
                }
            }
            
        } else {
            foreach (static::$_terminals as $pattern => $name) {
                if (preg_match($pattern, $string, $matches)) {
                    if ($name == Lexer::COMMENT) {
                        $this->incomment=true;
                    }
                    // stops at first match
                    return array(
                        'match' => $matches[1],
                        'token' => $name,
                        'line' => $this->line+1,
                        'offset' => $this->offset
                    );
                }
            }
        }
        return false; // no match
    }

    // display a token, all data (toString)
    public static function tokenstr($token) {
        return "[ " . $token['token'] . ": " . 
            $token['match'] . 
            " (" . $token['line'] . "," . 
            $token['offset']. ") ]";
    }

    // display token info for a parsing error (actual token & offset)
    public static function tokenerr($token) {
        return $token['match'] . 
            " at position " . $token['offset']. ".";
    }

    // display location information
    public static function location($token) {
        return "Line " . $token['line'] . ", position " . 
            $token['offset'];
    }

    // add a PEREF to the PEREF table (from the parser)
    public static function addPeref($peref,$subs) {
        // don't care if it's already there...
        static::$_perefs[$peref] = $subs;
    }

    // for debugging; display safely and fixed format
    static function debugmsg($msg) {
        echo "\n<pre>".htmlspecialchars($msg)."</pre>\n";
    }

    // show the current line; check if it was PEREF'd
    protected function showline() {
        $indent = $this->indent;
        $num = $this->line;
        $line = $this->currline; 
        $color="";
        
        if ($this->currline != $this->input[$num]) {
            echo "<span style='color:blue;'>This line has been modified by a parameter entity reference and is now:</span><br/>\n";
            $color='color:blue;';
        } 
        
        echo "<code style='white-space:pre;".$color."'>".
                str_pad((string) ($num+1), $indent, " ", STR_PAD_LEFT).": ".htmlspecialchars(trim($line,"\n\r"))."</code><br/>\n";
        
    }

    // print a ^ at the current offset plus the line # heading
    // for error messages that follow this
    public function printOffset($offset) {
        echo "<code style='white-space:pre;'>".
            str_pad("",$this->indent+2," ", STR_PAD_LEFT).
            str_pad("",$offset," ", STR_PAD_LEFT)."^</code><br/>\n";
    }
}
?>