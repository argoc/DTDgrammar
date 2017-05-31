<?php

/*
parseDTD Copyright 2016, Amelia Garripoli
http://faculty.olympic.edu/agarripoli
Free to use under the MIT license.
http://www.opensource.org/licenses/mit-license.php
11/6/2016
*/

// Parser : 

// ideas from 
// http://nitschinger.at/Writing-a-simple-lexer-in-PHP/
// http://www.codediesel.com/php/building-a-simple-parser-and-lexer-in-php/

// tokens from W3's spec
// https://www.w3.org/TR/REC-xml/#NT-intSubset

// limitations:
// * only ASCII in identifiers
// * processing instruction support is too loose (not restricting <?xml to only first line of external file, not restricting other PIs to not be <?xml)
// * allow more than a space between some things
//   that w3 says to only allow spaces
// * Not checking attribute value strings for proper references within
// * Not parsing within <!ENTITY that is not a simple peref or <!NOTATION
// * purely syntactic parsing, not cross-referencing external perefs

// can't parse without lex
require_once('lexer.php');

// parses DTD BNF
// needs a lexer that is able to move through the stream.
// lookahead(1). 
class Parser {

    public $input;     // from where do we get tokens?
    public $lookahead; // the current lookahead token
    public $DEBUG;
    public $errs;
    
    public function Parser(Lexer $input) {
        $this->input = $input;
        $this->errs = 0;
        $this->_consume(); // prime the input
    }

    // return true if there is a $x, otherwise false
    // does not consume; false if EOF too
    protected function _expect($x) {
        if ($this->lookahead == null) {
            return false;
        }
        return ($this->lookahead['token'] == $x);
    }

    /** If lookahead token type matches x, consume & return else error */
    protected function _match($x) {
        $this->_noEof($x);
        if ($this->lookahead['token'] == $x ) {
            $this->_consume();
        } else {
            throw new LogicException("Expecting " .
                        $x . ", found " . 
                        Lexer::tokenerr($this->lookahead));
        }
    }
    
    /** Skip lookahead tokens that match type x, consume
    and leave lookahead on first one past the type.
    Assumes there is a first token available and
    that EOF won't be reached (exception if so)
    */
    protected function _matchAny($x) {
        while ($this->lookahead['token'] == $x ) {
            $this->_consumeNoEof($x);
        } 
    }

    /*
      if at EOF then throw an exception.
    */
    protected function _noEof($x) {
        if ($this->lookahead == null) {
            throw new LogicException("Expecting " .
                                $x .
                                " : Unexpected EOF");
        }
    }

    /* 
       Skip lookahead tokens that match type x, consume and leave lookahead on first one past the type;
       have to have at least one. 
     */
    protected function _matchAtLeastOne($x) {
        $this->_noEof($x);
	if ($this->lookahead['token'] != $x) {
		throw new LogicException("Expecting " .
                        $x . ", found " . 
                        Lexer::tokenerr($this->lookahead));
	}
        while ($this->lookahead['token'] == $x ) {
            $this->_consumeNoEof($x);
        }
    }
	
    /* 
       Only allow a * of all the multiples... 
     */
    protected function _matchStar() {
	if ($this->_expect(Lexer::MULTIPLE) && 
		         ($this->lookahead['match'] == '*' ) {
            $this->_consume();
	} else {
            throw new LogicException("Expecting *, found " .
                        Lexer::tokenerr($this->lookahead));
	}
    }

    // in some, not all, contexts
    // NAME is followed by an optional ?+* (no spaces between)
    protected function _matchNameMultiple() {
        if ($this->_expect(Lexer::NAME) ||
            $this->_isKeyword() ||
            $this->_expect(Lexer::PEREF)) { 
                $this->_consume();
        } else {
            $this->_match(Lexer::NAME); // tell 'em we wanted a NAME
        }
        // can have +?* directly after.
        if ($this->_expect(Lexer::MULTIPLE)) {
               $this->_consume();
        }
    }
    
    // often a keyword can become a NAME ...
    protected function _isKeyword() {
        switch ($this->_type()) {
            case Lexer::ANY:
            case Lexer::CDATA:
            case Lexer::EMPTIER:
            case Lexer::ENTITIES:
            case Lexer::IDREFS:
            case Lexer::ID:
            case Lexer::NDATA:
            case Lexer::NMTOKENS:
            case Lexer::NOTATION:
            case Lexer::PUBLICLY:
            case Lexer::SYSTEM:
                return true;
            default: return false;
        }
    }

    // not all PEREFs cleanly subs, and they often stand in
    // for names, so take them as names. keywords also.
    // but the error will just say name expected,
    // because in this situation, keywords are not keywords!
    protected function _matchNameOrPeRef() {
        if ($this->_expect(Lexer::NAME) ||
            $this->_isKeyword() ||
            $this->_expect(Lexer::PEREF)) { 
                $this->_consume();
        } else {
            $this->_match(Lexer::NAME); // tell 'em we wanted a NAME
        }
    }

    // move ahead a token.
    protected function _consume() {
        if ($this->DEBUG) {
            echo htmlspecialchars(Lexer::tokenstr($this->lookahead))."<br/>";            
        }
        $this->lookahead = $this->input->nextToken();
    }

    // move ahead a token and make sure there is a next token
    protected function _consumeNoEof($x) {
        $this->_consume();
        $this->_noEof($x);
    }

    // look at the token type
    protected function _type() {
        return ($this->lookahead['token']);
    }

    // are we sitting on EOF?
    protected function _isEof() {
        return ($this->lookahead==null);
    }
    
    /* a fast-and-dirty lexical analysis, no real parsing */
    public function dumpTokens() {
        if ($this->DEBUG) {
            echo htmlspecialchars(Lexer::tokenstr($this->lookahead))."<br/>";
        }
    
        while($this->lookahead != null) {
            $this->_consume();
        }
    }
    
    // DTD: (elt | attl | ent | not | PI | cmt | PERef | S )*
    /*
[28b]   	intSubset  ::=   	(markupdecl | DeclSep)*
[28a]   	DeclSep	   ::=   	PEReference | S	
[29]   	markupdecl	   ::=   	elementdecl | AttlistDecl | EntityDecl | NotationDecl | PI | Comment
[69]   	PEReference	   ::=   	'%' Name ';'
    */
    // entry point for DTD parsing
    public function parseDTD() {
        // keep going
        $dontbother=false;
        while (! $this->_isEof()) {
            try {
                switch ($this->_type()) {
                    case Lexer::ELEMENT: 
                        $this->element();
                        break;

                    case Lexer::ATTLIST: 
                        $this->attlist();
                        break;

                    case Lexer::ENTITY:
                        $this->entityDecl();
                        break;

                    case Lexer::NOTATION:
                        // not parsed, just skip
                        if ($this->DEBUG) {
                             echo "Not parsing contents of ".htmlspecialchars($this->lookahead['match']).", skipping to ><br/>";
                        }
                        $this->skipToEndOfRule(Lexer::ENDRULE);
                        break;

                    case Lexer::PROCINST:
                        // not parsed, just skip
                        if ($this->DEBUG) {
                            echo "Not parsing contents of ".htmlspecialchars($this->lookahead['match']).", skipping to ?><br/>";
                        }
                        $this->skipToEndOfRule(Lexer::ENDPI);
                        break;

                    case Lexer::COMMENT: 
                        $this->finishComment();
                        break;

                    case Lexer::PEREF: 
                    case Lexer::SPACE: 
                        $this->_consume();
                        break;
                        
                    default:
                        throw new LogicException("Expecting start of a rule, found "  . 
                                     Lexer::tokenerr($this->lookahead));
                        $dontbother = true;
                        break;
                }
            } catch (LogicException $e) {
                $this->errs++;
                $this->input->printOffset($this->lookahead['offset']);
                ?><span style='color:red;'>
                <?= htmlspecialchars($e->getMessage()) ?>
                 Skipping to end of rule.
                 <br/></span><?php
                if ($this->DEBUG) {
                    Lexer::debugmsg($e->getTraceAsString());
                }
                try {
                    $this->skipToEndOfRule(Lexer::ENDRULE);
                } catch (LogicException $e)  {
                    $this->errs++;
                    $this->input->printOffset($this->lookahead['offset']);
                    ?><span style='color:red;'>
                     <?= htmlspecialchars($e->getMessage()) ?>
                     <br/></span><?php
                    if ($this->DEBUG) {
                        Lexer::debugmsg($e->getTraceAsString());
                    }
                }

            }         
        }
        return $this->errs;
    }

    // go until you find a > ...
    // might be useful for error recovery to
    // keep checking after an error.
    // leaves you on the first symbol past the >.
    protected function skipToEndOfRule($endTok) {
        $startTok = $this->lookahead;
        do {
            $this->_consume();
            if ($this->_isEof()) {
                throw new LogicException("Rule starting ". Lexer::location($startTok) ." not closed.");
            }
        } while (! $this->_expect($endTok));
        $this->_consume();
    }

    // not implemented yet, throw up
    protected function notyet() {
        throw new LogicException("Didn't implement, found "  . 
                                 Lexer::tokenerr($this->lookahead));
    }

/* <!ATTLIST
[52]   	AttlistDecl	   ::=   	'<!ATTLIST' S Name AttDef* S? '>'
[53]   	AttDef	   ::=   	S Name S AttType S DefaultDecl 
*/
    protected function attlist() {
        if ($this->DEBUG) {
            echo "Parsing ATTLIST...<br/>";
        }
        
        $this->_consume(); // <!ATTLIST
        $this->_matchAtLeastOne(Lexer::SPACE);
        $this->_matchNameOrPeRef();
        $this->_matchAny(Lexer::SPACE);

        // [53]   	AttDef	   ::=   	S Name S AttType S DefaultDecl 
        // turn it around to put the space at the end
        // (ok since there is optional space before ENDRULE
        // and there has to be a space before a Name...)

        do {
            $this->_matchNameOrPeRef();
            $this->_matchAtLeastOne(Lexer::SPACE);
            $this->attType();
            $this->_matchAny(Lexer::SPACE); // require? if no default?
            $this->defaultDecl();
            $this->_matchAny(Lexer::SPACE);
        } while (! $this->_expect(Lexer::ENDRULE));
        $this->_consume(); // >
    }

/*
[54]   	AttType	   ::=   	StringType | TokenizedType | EnumeratedType
[55]   	StringType	   ::=   	'CDATA'
[56]   	TokenizedType	   ::=   	'ID' | 'IDREF'	| 'IDREFS'	
			| 'ENTITY'	| 'ENTITIES' | 'NMTOKEN' | 'NMTOKENS'
[57]   	EnumeratedType	   ::=   	NotationType | Enumeration
[58]   	NotationType	   ::=   	'NOTATION' S '(' S? Name (S? '|' S? Name)* S? ')' 	
[59]   	Enumeration	   ::=   	'(' S? Nmtoken (S? '|' S? Nmtoken)* S? ')'
*/
    protected function attType() {

        switch ($this->_type()) {
            case Lexer::CDATA: 
            case Lexer::ID: 
            case Lexer::IDREFS: 
            case Lexer::ENTITIES: 
            case Lexer::NMTOKENS: 
                $this->_consume();       
                break;

            case Lexer::NOTATION: 
                $this->_consume();       
                $this->_matchAtLeastOne(Lexer::SPACE);
                $this->_match(Lexer::LPAREN);
                $this->_matchAny(Lexer::SPACE);
                $this->_matchNameOrPeRef();
                $this->_matchAny(Lexer::SPACE);

                // NAMELIST with PIPES until RPAREN
                if ($this->_expect(Lexer::PIPE)) {
                    do {
                        $this->_match(Lexer::PIPE);
                        $this->_matchAny(Lexer::SPACE);
                        $this->_matchNameOrPeRef();
                        $this->_matchAny(Lexer::SPACE);
                    } while (! $this->_expect(Lexer::RPAREN));
                    
                    $this->_consume();
                }
                break;

            case Lexer::LPAREN: // enumeration
                $this->_consume();       
                $this->_matchAny(Lexer::SPACE);
                
                // a pipe-list of NMTOKENS (more than names!)
                if ($this->_expect(Lexer::NAME) ||
                    $this->_expect(Lexer::NMTOKEN)) {
                    $this->_consume();   
                }
                $this->_matchAny(Lexer::SPACE);

                // NMTOKEN list with PIPES until RPAREN
                if ($this->_expect(Lexer::PIPE)) {
                    do {
                        $this->_match(Lexer::PIPE);
                        $this->_matchAny(Lexer::SPACE);
                        if ($this->_expect(Lexer::NAME) ||
                            $this->_expect(Lexer::NMTOKEN)) {
                            $this->_consume();   
                        }
                        $this->_matchAny(Lexer::SPACE);
                    } while (! $this->_expect(Lexer::RPAREN));
                    
                }
                // check because may not have had a pipe
                $this->_match(Lexer::RPAREN); 
                break;

            default:
                throw new LogicException("Expecting attribute type, found "  . 
                     Lexer::tokenerr($this->lookahead));

                break;
        }
        
    }

/*
[60]   	DefaultDecl	   ::=   	'#REQUIRED' | '#IMPLIED'
			| (('#FIXED' S)? AttValue)	
[10]   	AttValue	   ::=   	\'\" \' ([\^\<\&"] | Reference)* '"'
			|  "'" ([^<&'] | Reference)* "'"
        NOTE WE PUNT and don't look for valid references inside.
[67]   	Reference	   ::=   	EntityRef | CharRef
[68]   	EntityRef	   ::=   	'&' Name ';'
[66]   	CharRef	   ::=   	'&#' [0-9]+ ';'
			| '&#x' [0-9a-fA-F]+ ';'
*/
    protected function defaultDecl() {

        switch ($this->_type()) {
            case Lexer::REQUIRED:
            case Lexer::IMPLIED:
                $this->_consume();
                break;
            
            case Lexer::FIXED: 
                $this->_consume();
                $this->_matchAtLeastOne(Lexer::SPACE);

                // fall through to AttValue matching now ...
                
            default: // AttValue without FIXED before it
                // Not parsing the string, and WE SHOULD (see the BNF)
                // to validate the entity and char refs within it
                // parse it separately, not in the big lexer TODO
                $this->_match(Lexer::LITERAL);
                break;
        }
    }

    // need to grab PEREF definitions for
    // the lexer. ignore the rest...
    /*
[70]   	EntityDecl	   ::=   	GEDecl | PEDecl
[71]   	GEDecl	   ::=   	'<!ENTITY' S Name S EntityDef S? '>'
[72]   	PEDecl	   ::=   	'<!ENTITY' S '%' S Name S PEDef S? '>'
[73]   	EntityDef	   ::=   	EntityValue | (ExternalID NDataDecl?)
[74]   	PEDef	   ::=   	EntityValue | ExternalID 
[75]   	ExternalID	   ::=   	'SYSTEM' S SystemLiteral
			| 'PUBLIC' S PubidLiteral S SystemLiteral
[76]   	NDataDecl	   ::=   	S 'NDATA' S Name 
[9]   	EntityValue	   ::=   	'"' ([^%&"] | PEReference | Reference)* '"'
			|  "'" ([^%&'] | PEReference | Reference)* "'"
[67]   	Reference	   ::=   	EntityRef | CharRef
[68]   	EntityRef	   ::=   	'&' Name ';'
[66]   	CharRef	   ::=   	'&#' [0-9]+ ';'
			| '&#x' [0-9a-fA-F]+ ';'

    */
    protected function entityDecl() {
        
        $this->_consume(); // <!ENTITY
        $this->_matchAtLeastOne(Lexer::SPACE);
        
        switch ($this->_type()) {
            case (Lexer::NAME):
                $this->_consume(); // a name
                $this->_matchAtLeastOne(Lexer::SPACE);

                // TODO: check there is at least SOMETHING before >
                echo "Not parsing contents of &lt;!ENTITY, skipping to >.<br/>";
                $this->skipToEndOfRule(Lexer::ENDRULE);
                break;
                
            case (Lexer::PERCENT):
                $this->_consume(); // %
                $this->_matchAtLeastOne(Lexer::SPACE);
                $name = $this->lookahead['match']; // the name
                $this->_match(Lexer::NAME);
                $this->_matchAtLeastOne(Lexer::SPACE);
                
                // the hairy problem is that there can be
                // PEREFs within the strings that ALSO have to
                // be substituted for ... only within THESE strings
                // however. Go figure. We will PUNT.
                // We also won't support EXTERNAL/SYSTEM references
                // in our substitutions.
                
                if ($this->_expect(Lexer::LITERAL)) {
                    // need to check no "'s in the value,
                    // spec disallows (stops injection issues)
                    $subs = substr($this->lookahead['match'],1,-1);
                    if (strpos($subs,'"') !== false) {
                        throw new LogicException("Invalid string value for entity reference, contains a double quote. Token: ".Lexer::tokenerr($this->lookahead));
                    }
                    Lexer::addPeRef($name,$subs);
                    $this->_consume();
                    // end of rule
                    $this->_matchAny(Lexer::SPACE);
                    $this->_match(Lexer::ENDRULE);
                } else {
                        // TODO: check there is at least SOMETHING before >
                        if ($this->DEBUG) {
                            echo "Rule for &lt;!ENTITY may have SYSTEM, PUBLIC, or be incorrect (string not found), skipping to >.<br/>";
                        }
                        $this->skipToEndOfRule(Lexer::ENDRULE);
                }
                break;
                
            default: // TODO?
                throw new LogicException("Invalid token for entity definition. Token: ".Lexer::tokenerr($this->lookahead));
                break;
        }
                
    }

    
    // <!ELEMENT name ANY|EMPTY|content>
/*
[45]   	elementdecl	   ::=   	'<!ELEMENT' S Name S contentspec S? '>'	
[46]   	contentspec	   ::=   	'EMPTY' | 'ANY' | Mixed | children 
[51]   	Mixed	   ::=   	'(' S? '#PCDATA' (S? '|' S? Name)* S? ')*'
			| '(' S? '#PCDATA' S? ')' 
[47]   	children	   ::=   	(choice | seq) ('?' | '*' | '+')?
[48]   	cp	   ::=   	(Name | choice | seq) ('?' | '*' | '+')?
[49]   	choice	   ::=   	'(' S? cp ( S? '|' S? cp )+ S? ')'	
[50]   	seq	   ::=   	'(' S? cp ( S? ',' S? cp )* S? ')'
*/
    protected function element() {
        $this->_consume(Lexer::SPACE); // <!ENTITY known

        $this->_matchAtLeastOne(Lexer::SPACE);
        $this->_matchNameOrPeRef();
        $this->_matchAtLeastOne(Lexer::SPACE);
        
        switch ($this->_type()) {
            case Lexer::ANY:
            case Lexer::EMPTIER:
                $this->_consume();
                // nothing else except end of rule
                break;

            case Lexer::LPAREN:
                $this->elementContent();
                break;
         
            // not all PEREFs will substitute, so let it be here
            case Lexer::PEREF:
                $this->_consume();
                break;
                
            default: 
                throw new LogicException("Expecting ANY, EMPTY or element content, found "  . 
                                 Lexer::tokenerr($this->lookahead));
                break;
        }
        
        $this->_matchAny(Lexer::SPACE);
        $this->_match(Lexer::ENDRULE);
    }
    
    // (#PCDATA[[|] name]*)* | (name[[,|] name]*) 
    // need to deal with recursion also and *+?
    /*
    [46]   	contentspec	   ::=   ... Mixed | children 
    [51]   	Mixed	   ::=   	'(' S? '#PCDATA' (S? '|' S? Name)* S? ')*'
			| '(' S? '#PCDATA' S? ')' 

    [47]   	children	   ::=   	(choice | seq) ('?' | '*' | '+')?
    [48]   	cp	   ::=   	(Name | choice | seq) ('?' | '*' | '+')?
    [49]   	choice	   ::=   	'(' S? cp ( S? '|' S? cp )+ S? ')'	
    [50]   	seq	   ::=   	'(' S? cp ( S? ',' S? cp )* S? ')'
    
    XML spec doesn't say to allow it but PEREF as content
    also seems to be valid. May need entity ref as Name alternative also ... NOT IN THE SPEC????
    */
    protected function elementContent() {

        if ($this->DEBUG) {
            echo "Parsing !ELEMENT...<br/>";
        }
        $this->_consume(); // the LPAREN
        $this->_matchAny(Lexer::SPACE);

        // TODO ...
        // #PCDATA-starting-list or namelist
        switch ($this->_type()) {
            case Lexer::PCDATA:
                $this->_consume();
                // bar, space, rparen.
                $this->_matchAny(Lexer::SPACE);
                
                // pipe means list of elts allowed...
                // repeat until RPARENSTAR
                if ($this->_expect(Lexer::PIPE)) {
                    // got a pipe, it's pipe name until paren
                    
                    do {
                        $this->_consume(); // consume the PIPE
                        $this->_matchAny(Lexer::SPACE);
                        $this->_matchNameMultiple();
                        $this->_matchAny(Lexer::SPACE);
                    } while (! $this->_expect(Lexer::RPAREN)); // another pipe?
                    
                    $this->_match(Lexer::RPAREN);
                    $this->_match(Lexer::MULTIPLE); 
-                   // TODO that MULTIPLE must be a star...
+                   // maybe this $this->_matchStar(); 
			/*
-                        $this->_matchNameMultiple();
+                        $this->_matchNameOrPeRef();
-                    } while (! $this->_expect(Lexer::RPAREN));
+                    } while ($this->_expect(Lexer::PIPE));
  
			*/
                }
                else {
                    $this->_match(Lexer::RPAREN);
                }
                return;
                break;

                // a name or any keyword ...
                // in this context they are just names!
            case Lexer::ANY:
            case Lexer::CDATA:
            case Lexer::EMPTIER:
            case Lexer::ENTITIES:
            case Lexer::IDREFS:
            case Lexer::ID:
            case Lexer::NDATA:
            case Lexer::NMTOKENS:
            case Lexer::NOTATION:
            case Lexer::PUBLICLY:
            case Lexer::SYSTEM:
            case Lexer::NAME:
            case Lexer::PEREF: // TODO do the subs!
                // comma, bar, space, rparen.
                // will return on paren, use _expect!
                $this->_consume();
                if ($this->_expect(Lexer::MULTIPLE)) {
                    $this->_consume();
                }
                $this->_matchAny(Lexer::SPACE);
                
                if ($this->_expect(Lexer::PIPE) ||
                    $this->_expect(Lexer::COMMA)) {
                    $this->restOfNameList();
                }
                break;

            case Lexer::LPAREN:
                // will recurse on opening paren
                $this->nameList();

                // see if it continues...
                $this->_matchAny(Lexer::SPACE);
                
                if ($this->_expect(Lexer::PIPE) ||
                    $this->_expect(Lexer::COMMA)) {
                    $this->restOfNameList();
                }
                break;
                
            default:
                throw new LogicException(
                    "Expecting #PCDATA, name, internal parameter entity reference, or parentheses, found "  . 
                    Lexer::tokenerr($this->lookahead));
                break;
        }
        
        $this->_matchAny(Lexer::SPACE);
        $this->_match(Lexer::RPAREN);
    
        // can have +?* directly after.
        if ($this->_expect(Lexer::MULTIPLE)) {
               $this->_consume();
        }
    }
    
    // sitting on a PIPE or a COMMA (Q)
    // from now on get Q NameOrList Q NameOrList ... until not a Q.
    protected function restOfNameList() {
        $sep = $this->_type();
        
        while ($this->_expect($sep)) {
            $this->_consume(); // eat the separator
            $this->_matchAny(Lexer::SPACE);

            if ($this->_expect(Lexer::NAME) ||
                $this->_isKeyword() ||
                $this->_expect(Lexer::PEREF)) {
                $this->_matchNameMultiple();
            } else if ($this->_expect(Lexer::LPAREN)) {
                $this->nameList();
            } else {
                throw new LogicException("Expecting ( or name, found "  . 
                    Lexer::tokenerr($this->lookahead));
            }
            $this->_matchAny(Lexer::SPACE);
        }
    }

    //had a lparen, want the list within it
    // can have nested lists
    protected function nameList() {

        $this->_consume(); // LPAREN known
        $this->_matchAny(Lexer::SPACE);

        if ($this->_expect(Lexer::LPAREN)) {
            $this->nameList(); // recurse
        } else
        if ($this->_expect(Lexer::NAME) ||
            $this->_isKeyword() ||
            $this->_expect(Lexer::PEREF)) {
            $this->_consume();
            // can have +?* directly after.
            if ($this->_expect(Lexer::MULTIPLE)) {
                   $this->_consume();
            }
            $this->_matchAny(Lexer::SPACE);

            if ($this->_expect(Lexer::PIPE) ||
                $this->_expect(Lexer::COMMA)) {
                $this->restOfNameList();
            }
        } else {
            throw new LogicException("Expecting ( or name, found "  . 
                    Lexer::tokenerr($this->lookahead));
        }
        
        $this->_matchAny(Lexer::SPACE);

        // may not need these here ...
        if ($this->_expect(Lexer::PIPE) ||
            $this->_expect(Lexer::COMMA)) {
            $this->restOfNameList();
        }

        $this->_match(Lexer::RPAREN);
        // can have +?* directly after.
        if ($this->_expect(Lexer::MULTIPLE)) {
               $this->_consume();
        }
        
        $this->_matchAny(Lexer::SPACE);
    }

    // comment: <!-- (anything except --)* -->
    // had an opening comment, read until it closes
    // will be after close when done.
    // comments put the lexer in a different mode, won't see
    // keywords and will toss an error if a -- is seen without a >.
    protected function finishComment() {
        $startTok = $this->lookahead;
        do {
            if ($this->DEBUG) {
                echo "C: ";
            }
            $this->_consume();
            if ($this->_isEof()) {
                throw new LogicException("Comment near ". Lexer::location($startTok) ." not closed.");
            }
        } while (! $this->_expect(Lexer::ENDCOMMENT));
        $this->_consume();
    }

}
?>
