<?php

namespace MtHaml;

use MtHaml\Node\Root;
use MtHaml\Exception\SyntaxErrorException;
use MtHaml\Node\NodeAbstract;
use MtHaml\Parser\Buffer;
use MtHaml\Node\Doctype;
use MtHaml\Node\Tag;
use MtHaml\Node\TagAttribute;
use MtHaml\Node\Comment;
use MtHaml\Node\Insert;
use MtHaml\Node\Text;
use MtHaml\Node\InterpolatedString;
use MtHaml\Node\Run;
use MtHaml\Node\Statement;
use MtHaml\Node\NestInterface;
use MtHaml\Node\Filter;

class Parser
{
    protected $parentStack = array();
    protected $parent;

    protected $prev;

    protected $indentChar;
    protected $indentWidth;
    protected $prevIndentLevel = 0;
    protected $indentLevel = 0;

    protected $filename;
    protected $column;
    protected $lineno;

    public function __construct()
    {
        $this->parent = new Root;
    }

    public function checkIndent($buf, $indent)
    {
        $this->prevIndentLevel = $this->indentLevel;

        if (0 === strlen($indent)) {
            $this->indentLevel = 0;
            return;
        }

        if (null === $this->prev) {
            $this->syntaxError($buf, 'Indenting at the beginning of the document is illegal');
        }

        $char = count_chars($indent, 3 /* 3 = return all unique chars */);

        if (1 !== strlen($char)) {
            $this->syntaxError($buf, "Indentation can't use both tabs and spaces");
        }

        if (null === $this->indentChar) {

            $this->indentChar = $char;
            $this->indentWidth = strlen($indent);
            $this->indentLevel = 1;

        } else {

            if ($char !== $this->indentChar) {
                $expected = $this->indentChar === ' ' ? 'spaces' : 'tabs';
                $actual = $char === ' ' ? 'spaces' : 'tabs';
                $msg = sprintf('Inconsistent indentation: %s were used for indentation, but the rest of the document was indented using %s', $actual, $expected);
                $this->syntaxError($buf, $msg);
            }

            if (0 !== (strlen($indent) % $this->indentWidth)) {
                $msg = sprintf('Inconsistent indentation: %d is not a multiple of %d', strlen($indent), $this->indentWidth);
                $this->syntaxError($buf, $msg);
            }

            $indentLevel = strlen($indent) / $this->indentWidth;

            if ($indentLevel > $this->indentLevel + 1) {
                $this->syntaxError($buf, 'The line was indented more than one level deeper than the previous line');
            }

            $this->indentLevel = $indentLevel;
        }
    }

    public function getIndentString($levelOffset = 0, $fallback = null)
    {
        if (null !== $this->indentChar) {
            $width = $this->indentWidth * ($this->indentLevel + $levelOffset);
            return str_repeat($this->indentChar, $width);
        }

        $char = substr($fallback, 0, 1);
        if (' ' === $char || "\t" === $char) {
            return $char;
        }

        return '';
    }

    public function processStatement($buf, NodeAbstract $node)
    {
        // open tag or block

        if ($this->indentLevel > $this->prevIndentLevel) {

            $this->parentStack[] = $this->parent;
            $this->parent = $this->prev;

        // close tag or block

        } else if ($this->indentLevel < $this->prevIndentLevel) {

            $diff = $this->prevIndentLevel - $this->indentLevel;
            for ($i = 0; $i < $diff; ++$i) {
                $this->parent = array_pop($this->parentStack);
            }

        }

        // handle nesting

        if (!$this->parent instanceof NestInterface) {
            $parent = $this->parent;
            if ($parent instanceof Statement) {
                $parent = $parent->getContent();
            }
            $msg = sprintf('Illegal nesting: nesting within %s is illegal', $parent->getNodeName());
            $this->syntaxError($buf, $msg);
        }

        if ($this->parent->hasContent() && !$this->parent->allowsNestingAndContent()) {
            if ($this->parent instanceof Tag) {
                $msg = sprintf('Illegal nesting: content can\'t be both given on the same line as %%%s and nested within it', $this->parent->getTagName());
            } else {
                $msg = sprintf('Illegal nesting: nesting within a tag that already has content is illegal');
            }
            $this->syntaxError($buf, $msg);
        }

        if ($this->parent instanceof Tag && $this->parent->getFlags() & Tag::FLAG_SELF_CLOSE) {
            $msg = 'Illegal nesting: nesting within a self-closing tag is illegal';
            $this->syntaxError($buf, $msg);
        }

        $this->parent->addChild($node);
        $this->prev = $node;
    }

    public function parse($string, $filename, $lineno = 1)
    {
        $this->filename = $filename;

        $buf = new Buffer($string, $lineno);
        while ($buf->nextLine()) {
            $this->handleMultiline($buf);
            $this->parseLine($buf);
        }

        if (count($this->parentStack) > 0) {
            return $this->parentStack[0];
        } else {
            return $this->parent;
        }
    }

    public function handleMultiline($buf)
    {
        $line = $buf->getLine();

        if (!$this->isMultiline($line)) {
            return;
        }

        $line = substr($line, 0, -2);

        while ($next = $buf->peekLine()) {
            if (trim($next) == '') continue;
            if (!$this->isMultiline($next)) break;
            $line .= trim(substr($next, 0, -2));
            $buf->nextLine();
        }

        $buf->replaceLine($line);
    }

    public function isMultiline($string)
    {
        return ' |' === substr($string, -2);
    }

    protected function parseLine($buf)
    {
        $doctypeRegex = '/
            !!!                         # start of doctype decl
            (?:
                \s(?P<type>[^\s]+)      # optional doctype id
                (?:\s(?P<options>.*))?  # doctype options (e.g. charset, for
                                        # xml decls)
            )?$/Ax';

        if ($buf->match($doctypeRegex, $match)) {

            $type = empty($match['type']) ? null : $match['type'];
            $options = empty($match['options']) ? null : $match['options'];
            $node = new Doctype($match['pos'][0], $type, $options);
            $this->processStatement($buf, $node, '');

        } else {

            if ('' === trim($buf->getLine())) {
                return;
            }

            $buf->match('/[ \t]*/A', $match);
            $indent = $match[0];
            $this->checkIndent($buf, $indent);

            if (null === $node = $this->parseStatement($buf)) {
                $this->syntaxErrorExect($buf, 'statement');
            }
            $this->processStatement($buf, $node);
        }
    }

    protected function parseStatement($buf)
    {
        if ($buf->match('/
                    %(?P<tag_name>[\w:-]+)  # explicit tag name ( %tagname )
                    | (?=[.#][\w-])         # implicit div with class or id
                                            # ( .class or #id )
                /xA', $match))
        {
            $tag_name = empty($match['tag_name']) ? 'div' : $match['tag_name'];

            $attributes = $this->parseTagAttributes($buf);

            $flags = $this->parseTagFlags($buf);

            $node = new Tag($match['pos'][0], $tag_name, $attributes, $flags);

            $buf->skipWs();

            if (null !== $nested = $this->parseNestableStatement($buf)) {

                if ($flags & Tag::FLAG_SELF_CLOSE) {
                    $msg = 'Illegal nesting: nesting within a self-closing tag is illegal';
                    $this->syntaxError($buf, $msg);
                }

                $node->setContent($nested);
            }

            return $node;

        } else if (null !== $node = $this->parseFilter($buf)) {

            return $node;

        } else if (null !== $comment = $this->parseComment($buf)) {

            return $comment;

        } else if ($buf->match('/-(?!#)/A', $match)) {

            $buf->eatChar();
            $buf->skipWs();
            return new Run($match['pos'][0], $buf->getLine());

        } else {
            if (null !== $node = $this->parseNestableStatement($buf)) {
                return new Statement($node->getPosition(), $node);
            }
        }
    }

    protected function parseComment($buf)
    {
        if ($buf->match('!(-#|/)\s*!A', $match)) {
            $node = new Comment($match['pos'][0], $match[1] == '/');

            if (null !== $nested = $this->parseNestableStatement($buf)) {
                $node->setContent($nested);
            }

            return $node;
        }
    }

    protected function parseTagFlags($buf)
    {
        $flags = 0;
        while (null !== $char = $buf->peekChar()) {
            switch($char) {
                case '<':
                    $flags |= Tag::FLAG_REMOVE_INNER_WHITESPACES;
                    $buf->eatChar();
                    break;
                case '>':
                    $flags |= Tag::FLAG_REMOVE_OUTER_WHITESPACES;
                    $buf->eatChar();
                    break;
                case '/':
                    $flags |= Tag::FLAG_SELF_CLOSE;
                    $buf->eatChar();
                    break;
                default:
                    break 2;
            }
        }

        return $flags;
    }

    protected function parseTagAttributes($buf)
    {
        $attrs = array();

        // short notation for classes and ids

        while ($buf->match('/(?P<type>[#.])(?P<name>[\w-]+)/A', $match)) {
            if ($match['type'] == '#') {
                $name = 'id';
            } else {
                $name = 'class';
            }
            $name = new Text($match['pos'][0], $name);
            $value = new Text($match['pos'][1], $match['name']);
            $attr = new TagAttribute($match['pos'][0], $name, $value);
            $attrs[] = $attr;
        }

        // curly, ruby-like syntax

        if ($buf->match('/{\s*/A')) {

            do {
                $name = $this->parseAttrExpression($buf, '=,');

                if (!$buf->match('/\s*=>\s*/A')) {
                    $this->syntaxErrorExpected($buf, "'=>'");
                }

                $value = $this->parseAttrExpression($buf, ',');

                $attr = new TagAttribute($name->getPosition(), $name, $value);
                $attrs[] = $attr;

                if ($buf->match('/\s*}/A')) {
                    break;
                }
                if (!$buf->match('/\s*,\s*/A')) {
                    $this->syntaxErrorExpected($buf, "',' or '}'");
                }
                // allow line break after comma
                if ($buf->isEol()) {
                    $buf->nextLine();
                    $buf->skipWs();
                }

            } while (true);
        }

        // html-like syntax

        if ($buf->match('/\(\s*/A')) {

            do {
                if (!$buf->match('/[\w+:-]+/A', $match)) {
                    $this->syntaxErrorExpected($buf, 'html attribute name');
                }
                $name = new Text($match['pos'][0], $match[0]);

                if (!$buf->match('/\s*=\s*/A')) {
                    $this->syntaxErrorExpected($buf, "'='");
                }

                $value = $this->parseAttrExpression($buf, ' ');

                $attr = new TagAttribute($name->getPosition(), $name, $value);
                $attrs[] = $attr;

                if ($buf->match('/\s*\)/A')) {
                    break;
                }
                if (!$buf->match('/\s+/A')) {
                    $this->syntaxErrorExpected($buf, "' ' or ')'");
                }

            } while (true);
        }

        return $attrs;
    }

    protected function parseAttrExpression($buf, $delims)
    {
        if ($buf->match('/"/A', $match, false)) {
            return $this->parseInterpolatedString($buf);
        } else if ($buf->match('/:/A', $match, false)) {
            return $this->parseSymbol($buf);
        } else {
            list($expr, $pos) = $this->parseExpression($buf, $delims);
            return new Insert($pos, $expr);
        }
    }

    protected function parseExpression($buf, $delims)
    {
        // matches everything until a delimiter is found
        // delimiters are allowed inside quoted strings,
        // {}, and () (recursive)

        $re = "/(?P<expr>(?:
                [^(){}\"\'\\\\$delims]+
                | \"(?: [^\"\\\\]+ | \\\\[\"\\\\] )*\"
                | '(?: [^'\\\\]+ | \\\\['\\\\] )*'
                | \{ (?: (?P>expr) | [$delims] )+ \}
                | \( (?: (?P>expr) | [$delims] )+ \)
            )+)/xA";

        if ($buf->match($re, $match)) {
            return array($match[0], $match['pos'][0]);
        }

        $this->syntaxErrorExpected($buf, 'target language expression');
    }

    protected function parseSymbol($buf)
    {
        if (!$buf->match('/:(\w+)/A', $match)) {
            $this->syntaxErrorExpected($buf, 'symbol');
        }

        return new Text($match['pos'][0], $match[1]);
    }

    protected function parseInterpolatedString($buf, $quoted = true)
    {
        if ($quoted && !$buf->match('/"/A', $match)) {
            $this->syntaxErrorExpected('double quoted string');
        }

        $node = new InterpolatedString($buf->getPosition());

        if ($quoted) {
            $stringRegex = '/(
                    [^\#"\\\\]+     # anything without hash or " or \
                    |\\\\["\\\\]    # or escaped quote slash
                    |\#(?!\{)       # or hash, but not followed by {
                )+/Ax';
        } else {
            $stringRegex = '/(
                    [^\#]+      # anything without hash
                    |\#(?!\{)   # or hash, but not followed by {
                )+/Ax';
        }

        $exprRegex = '/
            \#\{(?P<insert>(?P<expr>
                [^\{\}"\']+
                | \{ (?P>expr)* \}
                | \'([^\'\\\\]+|\\\\[\'\\\\])*\'
                | "([^"\\\\]+|\\\\["\\\\])*"
            )+)\}
            /AxU';

        do {
            if ($buf->match($stringRegex, $match)) {
                $text = $match[0];
                if ($quoted) {
                    // strip slashes
                    $text = preg_replace('/\\\\(["\\\\])/', '\\1', $match[0]);
                }
                $text = new Text($match['pos'][0], $text);
                $node->addChild($text);
            } else if ($buf->match($exprRegex, $match)) {
                $expr = new Insert($match['pos']['insert'], $match['insert']);
                $node->addChild($expr);
            } else if ($quoted && $buf->match('/"/A')) {
                break;
            } else if (!$quoted && $buf->match('/$/A')) {
                break;
            } else {
                $this->syntaxErrorExpected($buf, 'string or #{...}');
            }
        } while (true);

        return $node;
    }

    protected function parseNestableStatement($buf)
    {
        if ($buf->match('/([&!]?)[=~]\s*/A', $match)) {

            $node = new Insert($match['pos'][0], $buf->getLine());

            if ($match[1] == '&') {
                $node->getEscaping()->setEnabled(true);
            } else if ($match[1] == '!') {
                $node->getEscaping()->setEnabled(false);
            }

            $buf->skipWs();

            return $node;
        }

        if (null !== $comment = $this->parseComment($buf)) {
            return $comment;
        }

        $position = array(
            'lineno' => $buf->getLineno(),
            'column' => $buf->getColumn(),
        );

        if ('\\' === $buf->peekChar()) {
            $buf->eatChar();
        }

        if (strlen(trim($buf->getLine())) > 0) {
            return $this->parseInterpolatedString($buf, false);
        }
    }

    protected function parseFilter($buf)
    {
        if (!$buf->match('/:(.*)/A', $match)) {
            return null;
        }

        $node = new Filter($match['pos'][0], $match[1]);

        while (null !== $next = $buf->peekLine()) {

            $indent = '';

            if ('' !== trim($next)) {
                $indent = $this->getIndentString(1, $next);
                if ('' === $indent) {
                    break;
                }
                if (strpos($next, $indent) !== 0) {
                    break;
                }
            }

            $buf->nextLine();
            $buf->eatChars(strlen($indent));
            $str = $this->parseInterpolatedString($buf, false);
            $node->addChild(new Statement($str->getPosition(), $str));
        }

        return $node;
    }

    protected function syntaxErrorExpected($buf, $expected)
    {
        $unexpected = $buf->peekChar();
        if ($unexpected) {
            $unexpected = "'$unexpected'";
        } else {
            $unexpected = 'end of line';
        }
        $msg = sprintf("Unexpected %s, expected %s", $unexpected, $expected);
        $this->syntaxError($buf, $msg);
    }

    protected function syntaxError($buf, $msg)
    {
        $this->column = $buf->getColumn();
        $this->lineno = $buf->getLineno();

        $msg = sprintf('%s in %s on line %d, column %d',
            $msg, $this->filename, $this->lineno, $this->column);

        throw new SyntaxErrorException($msg);
    }

    public function getColumn()
    {
        return $this->column;
    }

    public function getLineno()
    {
        return $this->lineno;
    }

    public function getFilename()
    {
        return $this->filename;
    }

}

