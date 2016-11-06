<!DOCTYPE html>
<html lang="en">
<head>
   <!--
parseDTD Copyright 2016, Amelia Garripoli
http://faculty.olympic.edu/agarripoli
Free to use under the MIT license.
http://www.opensource.org/licenses/mit-license.php
11/6/2016
   -->
    <meta charset="utf-8"/>
    <title>Check DTD Grammar</title>

    <meta name="description" content="DTD parser checks DTD syntax only">
    <meta name="keywords" content="DTD,XML,grammar,syntax,language,check,verify,validate,well-formed">
    <meta name="author" content="Amelia Garripoli">

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" 
          href="//fonts.googleapis.com/css?family=Raleway:400,300,600">
    <link rel="stylesheet" type="text/css" href="css/normalize.css">
    <link rel="stylesheet" type="text/css" href="css/skeleton.css">
    <link rel="stylesheet" type="text/css" href="css/dtd.css">
</head>
<body>
   
   <div class="container">
    <div class="row ten columns" style="margin-top: 5%">
    <h1 id="top">Check DTD Grammar</h1>

<?php 

    /*
    This page lets you upload a DTD or cut/paste a DTD.
    It does not want the <!DOCTYPE portion from an internal DTD.
    It will check the grammar against its BNF.
    There are limitations... see the comments below
    */

    // based on work found here:
    // http://www.codediesel.com/php/building-a-simple-parser-and-lexer-in-php/
    // data to test this with: http://edutechwiki.unige.ch/en/DTD_tutorial

// DTD parser to check DTD syntax
// DTD language rules from https://en.wikipedia.org/wiki/Document_type_definition
//and of course https://www.w3.org/TR/REC-xml/#NT-intSubset (where DTD BNF is formalized, starting at "intSubset")
// NOTE: external DTDs have a RICHER LANGUAGE with 
// parameterized entity references ... !! need a source.
    
//limitations/shortfalls:
//* not checking the PI's in the DTD.
//* only allowing ASCII letters in identifiers
//* allowing more than just space as whitespace in element rules (most do anyway)
//* does not do CONDITIONAL SUBSECTIONS. (only permitted in external DTD files in any case)
//* does not pull in external parameterized entities, so they can only be used where a simple NAME is expected...
//* we are allowing newlines some places where whitespace is not
//  acceptable (so we can track line #s and not overload preg/substr)
//* not checking char refs/entity refs within strings
//* not detecting cycles in PEREFs
//* ... see lexer.php and parser.php for more

    require_once('lexer.php');
    require_once('parser.php');
?>

    <script type="text/javascript">
        function showMe (it, box) {
            var vis = (box.checked) ? "block" : "none";
            document.getElementById(it).style.display = vis;
        }
    </script>

    <?php 
       $display = "";
       $checked = "";
       if (isset($_POST['submit'])) {
           $display="none";
       } else {
           $display = "block";
           $checked = 'checked="checked"';
       }
    ?>

    <p>
        <label for="showform">
        <input type="checkbox" id="showform" onclick="showMe('formdiv', this)"
          <?= $checked ?>
        >
        <span class="label-body">Show Form</span></label>
    </p>

    <div id="formdiv" class="u-cf" style="display:<?= $display ?>">
        <h5>Select a file or paste text below, choose what validation you want, and click Parse!</h5>
        <form id='18' enctype="multipart/form-data" method="post">
            <p>
               <label for="text">Select the file to upload here:</label>
               <input type="file" id="file" name="file"/> 
                   <?= (isset($_FILES['file']) 
                        && strlen($_FILES['file']['name'])>0)?
                       ('(was:'.$_FILES['file']['name'].')'):'' ?>
            </p>
            <p>
                <label for="text">Or paste the contents of your file here:</label><br/>
                <textarea type="file" row="25" id="text" name="text"><?= isset($_POST['text'])?$_POST['text']:'' ?></textarea>
            </p>
            <p>
                <label for="parse"><input type="checkbox" id="parse" name="parse" checked="checked">
                    <span class="label-body">Syntax check</span></label><br/>
                <label for="verbose"><input type="checkbox" id="verbose" name="verbose">
                    <span class="label-body">Tell me everything!</span></label><br/>
                <label for="lex"><input type="checkbox" id="lex" name="lex" >
                    <span class="label-body">Token check</span></label><br/>

                <button type="submit" name='submit' value="go">Parse!</button>
                <button type="reset" name='reset' value="no">Clear</button>
            </p>
        </form>
    </div>


<?php 
    if ($_SERVER['REQUEST_METHOD'] == 'POST') 
    {
        $input = null;
        if (isset($_POST['text']) && 
            strlen($_POST['text']) > 0) {
            $input = explode("\n",$_POST['text']);            
        } else if (isset($_FILES['file']) &&
                  $_FILES["file"]["tmp_name"]!=null &&
                  $_FILES["file"]["tmp_name"]!="") {
            $input = file($_FILES["file"]["tmp_name"]);
        }
        if ($input != null && count($input)>0) {
            if (isset($_POST['lex'])) {
                ?><h4 id='tout' class="u-cf">Token check <?php
                if (isset($_POST['parse'])) {
                    ?><a href='#sout'>Jump to Syntax check</a> <a href="#result">Jump to Results</a><?php
                } 
                ?></h4><?php
                $lexer = new Lexer($input,false);
                $parser = new Parser($lexer);
                $parser->DEBUG = isset($_POST['verbose']);
                // quiet unless debug set
                $parser->dumpTokens();
            }
            if (isset($_POST['parse'])) {
                ?><h4 id='sout' class="u-cf">Syntax check <?php
                if (isset($_POST['lex'])) {
                    ?><a href='#tout'>Jump to Token Check</a> <?php
                } 
                ?><a href="#result">Jump to Results</a></h4><?php
                $lexer = new Lexer($input,true);
                $parser = new Parser($lexer);
                $parser->DEBUG = isset($_POST['verbose']);
                // produces output as it goes through each line
                $res = $parser->parseDTD();
                if ($res > 0) {
                    ?><p id="result" style='color:red;'><strong><?= $res ?> error<?= (($res>1)?'s':'') ?> found.</strong>
                      </p>
                    <?php
                    
                } else {
                    ?><p id="result" style='color:green;'><strong>No errors found.</strong></p><?php
                }
            }
            ?><h4 class="u-cf"><a href='#top'>Jump to Form</a> <?php
            if (isset($_POST['lex'])) {
                ?><a href='#tout'>Jump to Token Check</a> <?php
            }
            if (isset($_POST['parse'])) {
                ?><a href='#sout'>Jump to Syntax check</a><?php
            } 
            ?></h4><?php
        } else {
            ?><p class="u-cf">No input detected.</p><?php
        }
    }
?>
       </div>
   <footer class="u-pull-left"><a href="aboutDTD.html">About DTD Grammar</a></footer>
</div>

</body>
</html>