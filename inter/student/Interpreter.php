<?php

/**
 * IPP - PHP Project 2, 2024
 * @author Samoilova Anastasiia (xsamoi00)
 * 
 */

namespace IPP\Student;

use DOMNode;
use DOMNodeList;
use DivisionByZeroError;
use DOMElement;

use IPP\Core\AbstractInterpreter;
use IPP\Core\Exception\IPPException;
use IPP\Core\Interface\InputReader;
use IPP\Core\Interface\OutputWriter;

//////////////////////////  EXCEPTIONS  //////////////////////////

    // Constants representing different error codes
    // These codes are used to identify specific types of errors
    // and are associated with meaningful error messages

class Wrong extends IPPException {
    const ERR_PARAMS = 10;
    const ERR_INPUT = 11;
    const ERR_OUTPUT = 12;
    const ERR_XML_FORMAT = 31;
    const ERR_XML_SYNTAX = 32;
    const ERR_XML_SEMANTIC = 52;
    const ERR_CODE_TYPE = 53;
    const ERR_CODE_VARIABLE = 54;
    const ERR_CODE_FRAME = 55;
    const ERR_CODE_VALUE = 56;
    const ERR_CODE_ZERO = 57;
    const ERR_CODE_STRING = 58;
    const ERR_INTERNAL = 99;

    const ERR_VALID_INTEGRACE = 88;

    public function __construct(int $code, string $msg = "") {
        parent::__construct($msg, $code, null);
    }

}

//////////////////////////  Argument  //////////////////////////

// Class representing an argument
// An argument consists of a type and a value'
class Argument
{
    private string $type;
    private string $value;
    /**
     * @var array<string, int> $_labels
     */
    protected static array $_labels = [];
    /**
     * @var string[] $_frame_global 
     */
    protected static array $_frame_global = [];
    /**
     * @var array<array<string>> $_frame_local
     */
    protected static ?array $_frame_local = [];
    /**
     * @var string[]|null $_frame_temp
     */
    protected static ?array $_frame_temp = null;
    /**
     * @var int[] $_call_stack
     */
    protected static array $_call_stack = [];

    public function __construct(string $type, string $value) {
        $this->type = $type;
        $this->value = $value;
    }

    public function get_type(): string {
        return $this->type;
    }
    
    public function get_val(): string {    
        return $this->value;
    }

}

// Class for working with variables and frames
// It provides methods for defining, setting and getting variables
// and for creating, pushing and popping frames
class For_Var extends Argument
{
    public function __construct() {
        $this->_frame_global = [];
        $this->_frame_temp = null;
        $this->_frame_local = [];
    }

     public function var_define(string $var): void
     {
        [$frame_typ, $var_name] = explode("@", $var);
        $dictionary = &$this->get_frame($frame_typ);
        if (array_key_exists($var_name, $dictionary)) {
            throw new Wrong(Wrong::ERR_XML_SEMANTIC, "Variable is already defined");
        }
        $dictionary[$var_name] = null;
    }

        public function var_set(string $var, mixed $value): void
        {
            [$frame_typ, $var_name] = explode("@", $var);
            $dictionary = &$this->get_frame($frame_typ);
            if (!array_key_exists($var_name, $dictionary)) {
                throw new Wrong(Wrong::ERR_CODE_VARIABLE, "Variable is not defined");
            }
            $dictionary[$var_name] = $value;
        }

        public function var_get(string $var): mixed 
        {
            [$frame_typ, $var_name] = explode("@", $var);
            $dictionary = &$this->get_frame($frame_typ);
            if (!array_key_exists($var_name, $dictionary)) {
                throw new Wrong(Wrong::ERR_CODE_VARIABLE, "Variable is not defined");
            }
            return $dictionary[$var_name];
        }

        public function frame_create(): void {
            $this->_frame_temp = array();
        }

        public function frame_push(): void {
            if ($this->_frame_temp === null) {
                throw new Wrong(Wrong::ERR_CODE_FRAME, "Temp frame is not defined\n");
            }

            $this->_frame_local[] = $this->_frame_temp;

            //TF is now undefined
            $this->_frame_temp = null;
        }

        public function frame_pop(): void { 
            if (empty($this->_frame_local)) {
                throw new Wrong(Wrong::ERR_CODE_FRAME, "Local frame is not defined\n");
            }

            $this->_frame_temp = array_pop($this->_frame_local);
        }

        /**
         * @return string[]
         */
        private function &get_frame(string $frame_typ): array {
            if ($frame_typ === "GF") {
                return $this->_frame_global;
            } else if ($frame_typ === "TF") {
                if ($this->_frame_temp === null) {
                    throw new Wrong(Wrong::ERR_CODE_FRAME, "Temporary frame is not defined");
                }

                return $this->_frame_temp;
            } else if ($frame_typ === "LF") {
                if (empty($this->_frame_local)) {
                    throw new Wrong(Wrong::ERR_CODE_FRAME, "Temporary frame is not defined");
                }
                return $this->_frame_local[count($this->_frame_local) - 1];
            } else {
                throw new Wrong(Wrong::ERR_CODE_STRING, "Unknown frame type");
            }            
        }
}

    // Class for working with labels
    // It provides methods for setting and getting labels
    // and for storing and getting return addresses
    class ForLabels extends Argument
    {

        public function __construct() {
            $this->_labels = [];
            $this->_call_stack = [];
        }

        public function _label_create(string $_label_name, int $pos): void {
            if (array_key_exists($_label_name, $this->_labels)) {
                throw new Wrong(Wrong::ERR_XML_SEMANTIC, "Label is already defined");
            }

            $this->_labels[$_label_name] = $pos;
        }

        public function label_get_index(string $label): int {
            if (!array_key_exists($label, $this->_labels)) {
                throw new Wrong(Wrong::ERR_XML_SEMANTIC, "Label is not defined");
            }

            return $this->_labels[$label];
        }

        public function get_address_safe(int $pos): void
        {
            $this->_call_stack[] = $pos;
        }

        public function return_address(): int
        {
            if (count(self::$_call_stack) === 0) {
                throw new Wrong(Wrong::ERR_CODE_VALUE, "Call stack is empty\n");
            }
            return array_pop(self::$_call_stack);
        }
    }
    
//////////////////////////  Instructions  //////////////////////////

// Class representing an instruction
// An instruction consists of an opcode and an array of arguments
// It provides an abstract method execute, which is implemented in child classes

class VariableType {
    const SYMBOL = "symbol";
    const VAR = "var";
    const LABEL = "label";
    const TYPE = "type";
    const INT = "int";
    const BOOL = "bool";
    const STRING = "string";
    const NIL = "nil";
    const FLOAT = "float"; // for FLOAT extension
}

class Instruction_Main {
    public function execute(int $count): int {
        // Implementation in child classes
        return ++$count;
    }

    // Check if the operand type is valid, otherwise throw an exception with error code 32
    protected function typeOperand(string $got_Type, string $expected_T): void {
        switch ($expected_T) {
            case VariableType::VAR:
                if ($got_Type !== "var") {
                    throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Invalid operand type');
                }
                break;
            case VariableType::LABEL:
                if ($got_Type !== "label") {
                    throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Invalid operand type');
                }
                break;
            case VariableType::TYPE:
                if ($got_Type !== "type") {
                    throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Invalid operand type');
                }
                break;
            case VariableType::SYMBOL:
                if (!in_array($got_Type, ['var', 'int', 'string', 'bool', 'nil'])) {
                    throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Invalid operand type');
                }
                break;
            default:
            throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Invalid operand type');
        }
    }

    // Check if the operand value is valid, otherwise throw an exception with error code 53
    protected function ValueOperand(mixed $got_Val, string $expected_V): void {
        $isValid = false;
    
        switch ($expected_V) {
            case VariableType::INT:
                $isValid = is_numeric($got_Val) && intval($got_Val) == $got_Val;
                break;
            case VariableType::BOOL:
                $isValid = in_array($got_Val, ['true', 'false', true, false], true);
                break;
            case VariableType::TYPE:
                $isValid = in_array($got_Val, ['string', 'int', 'bool', 'nil'], true);
                break;
            case VariableType::STRING:
                $isValid = is_string($got_Val) && $got_Val !== "nil@nil";
                break;
            case VariableType::NIL:
                $isValid = $got_Val === "nil";
                break;
        }
    
        if (!$isValid) {
            throw new Wrong(Wrong::ERR_CODE_TYPE, 'Invalid operand type');
        }
    }
    
}

class Type_Find extends Instruction_Main
    {
        private For_Var $var;
        /**
         * @var Argument[] $args
         */
        private array $args;
        
        /**
         * @param Argument[] $args
         */
        public function __construct(array $args, For_Var $var) {
            $this->var = $var;
            $this->args = $args;
        }

        public function execute(int $count): int {
            $this->typeOperand($this->args[0]->get_type(), VariableType::VAR);
            $this->typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
            
            $type = $this->args[1]->get_type();
    
            if ($type === "int" || $type === "string" || $type === "bool" || $type === "nil") 
            {
                $this->ValueOperand($this->args[1]->get_val(), VariableType::TYPE);
            } 
            else if ($type === "var") 
            {
                $type = $this->Receive_Type($this->args[1]->get_val());
            }
    
            $var_name = $this->args[0]->get_val();
            $this->var->var_set($var_name, $type);
    
            return ++$count;
        }
        // Function to determine the type of the variable
        private function Receive_Type(string $var_name): string 
        {
            $var_val = $this->var->var_get($var_name);
        
            if ($var_val === null) 
            {
                return "nil"; 
            } 
            elseif (is_numeric($var_val)) 
            {
                return "int";
            } 
            elseif ($var_val === true || $var_val === false) 
            {
                return "bool";
            } 
            else 
            {
                return "string";
            }
        }
    }

class STR2INT extends Instruction_Main
{
    protected For_Var $variables;
    /**
     * @var Argument[] $args
     */
    protected array $args;

    /**
     * @param Argument[] $args 
     */
    public function __construct(array $args, For_Var $variables) 
    {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);

        $string = $this->args[1]->get_val();
        if ($this->args[1]->get_type() === "var") {
            $string = $this->variables->var_get($this->args[1]->get_val());
        }

        $index = $this->args[2]->get_val();
        if ($this->args[2]->get_type() === "var") {
            $index = $this->variables->var_get($this->args[2]->get_val());
        }

        parent::ValueOperand($index, VariableType::INT);
        parent::ValueOperand($string, VariableType::STRING);

        $index = intval($index);
        if ($index < 0 || $index >= mb_strlen($string)) {
            throw new Wrong(Wrong::ERR_CODE_STRING, "Index out of range");
        }

        $char = mb_substr($string, $index, 1);
        $asciiCode = mb_ord($char);

        $this->variables->var_set($this->args[0]->get_val(), $asciiCode);

        return ++$count;
    }
}

class INT2CHAR extends Instruction_Main
{
    protected For_Var $variables;
    /**
     * @var Argument[] $args
     */
    protected array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) 
    {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);

        $asciiCode = $this->args[1]->get_val();
        if ($this->args[1]->get_type() === "var") 
        {
            $asciiCode = $this->variables->var_get($this->args[1]->get_val());
        }

        parent::ValueOperand($asciiCode, VariableType::INT);

        if ($asciiCode < 0 || $asciiCode > 1114111) 
        {
            throw new Wrong(Wrong::ERR_CODE_STRING, "Invalid ASCII code");
        }

        $char = mb_chr($asciiCode);

        $this->variables->var_set($this->args[0]->get_val(), $char);

        return ++$count;
    }
}


class AritmeticOperation extends Instruction_Main
{
    /**
     * @var Argument[] $args
     */
    protected array $args;
    protected For_Var $variables;
    protected int $first_var;
    protected int $second_V;
    protected string $opcode;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables, string $opcode)
    {
        $this->variables = $variables;
        $this->args = $args;
        $this->opcode = $opcode;
    }
    // podpora FLOAT
    public function make_Vales(): void
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);

        $this->first_var = $this->getValue($this->args[1]);
        $this->second_V = $this->getValue($this->args[2]);
    }

    protected function getValue(Argument $argument): int
    {
        $value = $argument->get_val();
        if ($argument->get_type() === "var") {
            $value = $this->variables->var_get($value);
        }
        parent::ValueOperand($value, VariableType::INT);
        return intval($value);
    }

    public function execute(int $count): int
    {
        $this->make_Vales();
        switch ($this->opcode) {
            case "ADD":
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var + $this->second_V);
                break;
            case "SUB":
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var - $this->second_V);
                break;
            case "MUL":
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var * $this->second_V);
                break;
            case "IDIV":
                if ($this->second_V === 0) 
                {
                    throw new Wrong(Wrong::ERR_CODE_ZERO, "division by zero");
                }
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var / $this->second_V);
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
        }

        return ++$count;
    }
}


class Break_Dprint_Label extends Instruction_Main
{
    public function execute(int $count): int 
    {
        return ++$count;
    }
}

    class ExitInst extends Instruction_Main
    {
        private For_Var $variables;
        /**
        * @var Argument[] $args
        */
        private array $args;

        /**
        * @param Argument[] $args
        */
        public function __construct(array $args, For_Var $variables) 
        {
            $this->variables = $variables;
            $this->args = $args;
        }

        public function execute(int $programCounter): int 
        {
            parent::typeOperand($this->args[0]->get_type(), VariableType::SYMBOL);

            $exitCode = $this->args[0]->get_val();
            if ($this->args[0]->get_type() === "var") {
                $exitCode = $this->variables->var_get($this->args[0]->get_val());
            }

            parent::ValueOperand($exitCode, VariableType::INT);

            $exitCode = intval($exitCode);
            
            if (0 <= $exitCode && $exitCode <= 9) 
            {
                exit($exitCode);
            } 
            else 
            {
                throw new Wrong(Wrong::ERR_CODE_ZERO,"Unknown exit code");
            }
        }

    }

class JUMP extends Instruction_Main
{
    private ForLabels $labels;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, ForLabels $labels) 
    {
        $this->labels = $labels;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::LABEL);
        return $this->labels->label_get_index($this->args[0]->get_val());
    }
}

// Class representing the JUMPIFEQ and JUMPIFNEQ instructions
class JumpQ_NQ extends Instruction_Main
{
    protected ForLabels $labels;
    protected For_Var $variables;
    /**
     * @var Argument[] $args
     */
    protected array $args;
    protected bool $areEqual;
    protected string $opcode; 

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables, ForLabels $labels, string $opcode) {
        $this->args = $args;
        $this->variables = $variables;
        $this->labels = $labels;
        $this->opcode = $opcode; 
    }

    // Function to compare the values of the variables
    public function make_Value(): void {
        parent::typeOperand($this->args[0]->get_type(), VariableType::LABEL);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);
    
        $firstVariableValue = $this->getValue($this->args[1]);
        $secondVariableValue = $this->getValue($this->args[2]);
    
        $this->areEqual = false;
    
        if (is_numeric($firstVariableValue)) 
        {
            $this->compareNumericValues($firstVariableValue, $secondVariableValue);
        }
        else if (in_array($firstVariableValue, ["false", "true", false, true], true)) 
        {
            $this->compareBooleanValues($firstVariableValue, $secondVariableValue);
        } 
        else if ($this->args[1]->get_type() === "nil") 
        {
            $this->areEqual = true;
        } 
        else if ($this->args[1]->get_type() === "string" || is_string($firstVariableValue)) 
        {
            $this->areEqual = $firstVariableValue === $secondVariableValue;
        } 
        else 
        {
            throw new Wrong(Wrong::ERR_CODE_TYPE, "Unknown type");
        }
    }
    
    private function getValue(Argument $arg): mixed
    {
        $value = $arg->get_val();
        if ($arg->get_type() === "var") {
            return $this->variables->var_get($value);
        }
        return $value;
    }
    
    private function compareNumericValues(mixed $first, mixed $second): void
    {
        parent::ValueOperand($second, VariableType::INT);
        $first = intval($first);
        $second = intval($second);
        $this->areEqual = $first === $second;
    }
    
    private function compareBooleanValues(mixed $first, mixed $second): void
    {
        parent::ValueOperand($second, VariableType::BOOL);
        if (is_string($first)) 
        {
            $first = $first === "false" ? false : true;
        }
        if (is_string($second)) 
        {
            $second = $second === "false" ? false : true;
        }
        $this->areEqual = $first === $second;
    }
    
    // Function to execute the instruction, it returns the next instruction index
        public function execute(int $count): int 
        {
            $this->make_Value();
    
            switch ($this->opcode) {
                case "JUMPIFEQ":
                    if ($this->areEqual) {
                        return $this->labels->label_get_index($this->args[0]->get_val()) + 1;
                    }
                    break;
                case "JUMPIFNEQ":
                    if (!$this->areEqual) {
                        return $this->labels->label_get_index($this->args[0]->get_val()) + 1;
                    }
                    break;
                default:
                    throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
            }
    
            return $count + 1; 
        }
}

class CALL extends Instruction_Main
{
    private ForLabels $labels;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, ForLabels $labels) 
    {
        $this->labels = $labels;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        $this->labels->get_address_safe($count);
        return $this->labels->label_get_index($this->args[0]->get_val());
    }
}


class DEFVAR extends Instruction_Main
{
    private For_Var $var;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $var) 
    {
        $this->var = $var;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        $this->var->var_define($this->args[0]->get_val());
        return ++$count;
    }
}

class MOVE extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) 
    {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        $varName = $this->args[0]->get_val();
        $value = $this->getValue($this->args[1]);
        $this->variables->var_set($varName, $value);
        return ++$count;
    }

    
    private function getValue(Argument $arg): mixed 
    {
        $value = $arg->get_val();
        $type = $arg->get_type();
        
        switch ($type) {
            case "var":
                return $this->variables->var_get($value);
            case "int":
                parent::ValueOperand($value, VariableType::INT);
                return intval($value);
            case "bool":
                parent::ValueOperand($value, VariableType::BOOL);
                return $value === "true" ? true : false;
            case "string":
                parent::ValueOperand($value, VariableType::STRING);
                return $value;
            case "nil":
                parent::ValueOperand($value, VariableType::NIL);
                return "nil@nil";
            default:
                throw new Wrong(Wrong::ERR_CODE_TYPE, "Unknown type");
        }
    }
}

class Push_Creat_Pop extends Instruction_Main
{
    private For_Var $var;
    private string $opcode;

    public function __construct(For_Var $var, string $opcode) {
        $this->var = $var;
        $this->opcode = $opcode;
    }

    public function execute(int $count): int {
        switch ($this->opcode) {
            case "CREATEFRAME":
                $this->var->frame_create();
                break;
            case "PUSHFRAME":
                $this->var->frame_push();
                break;
            case "POPFRAME":
                $this->var->frame_pop();
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
        }
        return ++$count;
    }
}

    class INSTR_Return extends Instruction_Main
    {
        private ForLabels $labels;

        public function __construct(ForLabels $labels) 
        {
            $this->labels = $labels;
        }

        public function execute(int $count): int 
        {
            
            return $this->labels->return_address();
        }

    }

class READ extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;
    private InputReader $input;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables, InputReader $input) 
    {
        $this->variables = $variables;
        $this->args = $args;
        $this->input = $input;
    }

    public function readValue(string $type): mixed
    {
        $value = null;
        switch ($type) {
            case "int":
                $value = $this->input->readInt();
                break;
            case "string":
                // Handle reading string value
                $value = $this->input->readString();
                break;
            case "bool":
                // Handle reading boolean value
                $value = $this->input->readBool();
                break;
            default:
                break;
        }

        return $value;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::TYPE);

        $varName = $this->args[0]->get_val();
        $type = $this->args[1]->get_val();

        // Read the value based on the type
        $value = $this->readValue($type);

        // Check if the value is of the expected type
        parent::ValueOperand($value, $type);

        // Set the variable value
        $this->variables->var_set($varName, $value);

        return ++$count;
    }
}

class WRITE extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;
    private OutputWriter $output;
    
    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables, OutputWriter $output) 
    {
        $this->variables = $variables;
        $this->args = $args;
        $this->output = $output;
    }

    public function execute(int $count): int 
    {
        // Get the type of the argument
        $type_oper = $this->args[0]->get_type();

        // Get the operand value
        $oper_Val = $this->args[0]->get_val();

        // Check if the operand type is valid
        if (!in_array($type_oper, ['var', 'nil', 'int', 'bool', 'string'], true)) 
        {
            throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Invalid operand type');
        }

        // Process the operand value based on its type
        switch ($type_oper) {
            case 'var':
                // Handle the case where operand value is null or empty
                if ($oper_Val === "") 
                {
                    throw new Wrong(Wrong::ERR_CODE_VALUE, 'Operand value is missing or empty');
                }
                // Get the value of the variable
                $oper_Val = $this->variables->var_get($oper_Val);
                if ($oper_Val === "nil@nil") $oper_Val = "";
                break;
            case 'nil':
                parent::ValueOperand($oper_Val, VariableType::NIL);
                $oper_Val = "";
                break;
            case 'string':
                parent::ValueOperand($oper_Val, VariableType::STRING);
                break;
            case 'bool':
                parent::ValueOperand($oper_Val, VariableType::BOOL);
                break;
            case 'int':
                parent::ValueOperand($oper_Val, VariableType::INT);
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Invalid operand type');
        }

        // Convert escape sequences to corresponding characters if oper_Val is a string
        if (is_string($oper_Val)) 
        {
            $oper_Val = preg_replace_callback(
                '/\\\\(\d{3})/', // Regular expression to match \ followed by exactly three digits
                function($matches) {
                    // Convert the matched digits to an integer and then to the corresponding ASCII character
                    return chr(intval($matches[1]));
                },
                $oper_Val
            );
        }

        // Convert boolean values to strings
        $oper_Val = ($oper_Val === true) ? "true" : (($oper_Val === false) ? "false" : $oper_Val);

        // Write the value to the output stream
        if (($oper_Val !== "") && ($oper_Val !== null))
        {
            $this->output->writeString($oper_Val);
        } 
        else 
        {
            throw new Wrong(Wrong::ERR_CODE_VALUE, 'Operand value is missing or empty');
        }

        return ++$count;
    }

}

class Logic_Instr extends Instruction_Main
{
    /**
     * @var Argument[] $args
     */
    protected array $args;
    protected For_Var $variables;
    protected string $opcode;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables, string $opcode) 
    {
        $this->variables = $variables;
        $this->args = $args;
        $this->opcode = $opcode;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);

        $firstVar = $this->getValue($this->args[1]);
        $Second_V = null;

        if ($this->opcode !== "NOT") 
        {
            parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
            parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);
            $Second_V = $this->getValue($this->args[2]);
        }

        $res = false;
        switch ($this->opcode) 
        {
            case "AND":
                $res = $firstVar && $Second_V;
                break;
            case "OR":
                $res = $firstVar || $Second_V;
                break;
            case "NOT":
                $res = !$firstVar;
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
        }

        $this->variables->var_set($this->args[0]->get_val(), $res);
        return ++$count;
    }

    protected function getValue(Argument $operand): mixed
    {
        $value = $operand->get_val();
        if ($operand->get_type() === "var") 
        {
            $value = $this->variables->var_get($value);
        }
        parent::ValueOperand($value, VariableType::BOOL);
        return is_string($value) ? $value === "true" : (bool) $value;
    }
}


class RelateInstr extends Instruction_Main
{
    /**
     * @var Argument[] $instructionArgs
     */
    protected array $instructionArgs;
    protected mixed $primaryValue;
    protected mixed $secondaryValue;
    protected For_Var $variableTable;
    protected ?bool $nilDetected;

    protected string $instructionCode;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables, string $opcode) 
    {
        $this->variableTable = $variables;
        $this->instructionArgs = $args;
        $this->nilDetected = null;
        $this->instructionCode = $opcode;
    }

    protected function make_Var(): void
    {
        parent::typeOperand($this->instructionArgs[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->instructionArgs[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->instructionArgs[2]->get_type(), VariableType::SYMBOL);

        $firstType  = $this->instructionArgs[1]->get_type();
        $secondType = $this->instructionArgs[2]->get_type();

        $this->primaryValue = $this->instructionArgs[1]->get_val();
        if ($firstType === "var") 
        {
            $this->primaryValue = $this->variableTable->var_get($this->instructionArgs[1]->get_val());
        }

        $this->secondaryValue = $this->instructionArgs[2]->get_val();
        if ($secondType === "var") 
        {
            $this->secondaryValue = $this->variableTable->var_get($this->instructionArgs[2]->get_val());
        }
        
        if ($firstType === "nil" || $this->primaryValue === "nil@nil" || $secondType === "nil" || $this->secondaryValue === "nil@nil") {
            $this->handleNilValues();
        } 
        else if (is_numeric($this->primaryValue)) 
        {
            $this->handleNumericValues();
        } 
        else if (in_array($this->primaryValue, ["false", "true", false, true], true)) 
        {
            $this->handleBooleanValues();
        } 
        else if (is_string($this->primaryValue)) 
        {
            $this->handleStringValues();
        }
    }

    // Function to handle nil values
    private function handleNilValues(): void
    {
        $this->normalizeNilValue($this->primaryValue);
        $this->normalizeNilValue($this->secondaryValue);
        $this->nilDetected = $this->primaryValue === $this->secondaryValue;
    }
    // Function to normalize the nil value
    private function normalizeNilValue(string &$value): void
    {
        if ($value === "nil@nil") {
            $value = "nil";
        }
    }
    
    private function handleNumericValues(): void
    {
        parent::ValueOperand($this->secondaryValue, VariableType::INT);
        $this->primaryValue = intval($this->primaryValue);
        $this->secondaryValue = intval($this->secondaryValue);
    }
    
    private function handleBooleanValues(): void
    {
        parent::ValueOperand($this->secondaryValue, VariableType::BOOL);
        $this->primaryValue = $this->primaryValue === "false" ? false : true;
        $this->secondaryValue = $this->secondaryValue === "false" ? false : true;
    }
    
    private function handleStringValues(): void {
        parent::ValueOperand($this->secondaryValue, VariableType::STRING);
        // No changes needed for string values
    }

    public function execute(int $count): int {
        $this->make_Var();
        switch ($this->instructionCode) {
            case "EQ":
                // Implementation
                $result = $this->nilDetected !== null ? $this->nilDetected : $this->primaryValue === $this->secondaryValue;
                $this->variableTable->var_set($this->instructionArgs[0]->get_val(), $result);
                break;
            case "GT":
                // Implementation
                if ($this->nilDetected !== null) {
                    throw new Wrong(Wrong::ERR_CODE_TYPE, "Expected a bool value");    
                }
                $this->variableTable->var_set($this->instructionArgs[0]->get_val(), $this->primaryValue > $this->secondaryValue);
                break;
            case "LT":
                // Implementation
                if ($this->nilDetected !== null) {
                    throw new Wrong(Wrong::ERR_CODE_TYPE, "Expected a bool value");     
                }
                $this->variableTable->var_set($this->instructionArgs[0]->get_val(), $this->primaryValue < $this->secondaryValue);
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
            }
        return ++$count;
    }
}
class CONCAT extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int {
        $string1 = '';
        $string2 = '';
    
        $this->typeAndSetValue($this->args[1], $string1);
        $this->typeAndSetValue($this->args[2], $string2);
    
        $this->variables->var_set($this->args[0]->get_val(), $string1 . $string2);
    
        return ++$count;
    }
    

    private function typeAndSetValue(Argument $arg, string &$stringValue): void {
        parent::typeOperand($arg->get_type(), VariableType::SYMBOL);
        
        if ($arg->get_type() === "var") {
            $stringValue = $this->variables->var_get($arg->get_val());
        } else {
            $stringValue = (string) $arg->get_val(); // Преобразуем в строку, если аргумент не переменная
        }
    
        parent::ValueOperand($stringValue, VariableType::STRING);
    }
}

class GETCHAR extends Instruction_Main
{

    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);

        $stringValue = $this->args[1]->get_val();
        if ($this->args[1]->get_type() === "var") {
            $stringValue = $this->variables->var_get($this->args[1]->get_val());
        }

        parent::ValueOperand($stringValue, VariableType::STRING);

        $i = $this->args[2]->get_val();
        if ($this->args[2]->get_type() === "var") {
            $i = $this->variables->var_get($this->args[2]->get_val());
        }

        parent::ValueOperand($i, VariableType::INT);
        $i = intval($i);

        if ($i < 0 || $i >= strlen($stringValue)) {
            throw new Wrong(Wrong::ERR_CODE_STRING, "Index out of range");
        }

        $char = $stringValue[$i];
        $this->variables->var_set($this->args[0]->get_val(), $char);

        return ++$count;
    }
}

class SETCHAR extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int {
        $varName = $this->args[0]->get_val();
        $varType = $this->args[0]->get_type();

        parent::typeOperand($varType, VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);

        $str_val = $this->variables->var_get($varName);

        parent::ValueOperand($str_val, VariableType::STRING);

        $i = $this->args[1]->get_val();
        if ($this->args[1]->get_type() === "var") 
        {
            $i = $this->variables->var_get($i);
        }

        parent::ValueOperand($i, VariableType::INT);
        $i = intval($i);

        $char = $this->args[2]->get_val();
        if ($this->args[2]->get_type() === "var") 
        {
            $char = $this->variables->var_get($char);
        }

        parent::ValueOperand($char, VariableType::STRING);

        if ($i < 0 || $i >= strlen($str_val)) 
        {
            throw new Wrong(Wrong::ERR_CODE_STRING, "Index out of range");
        }

        if (strlen($char) > 1) 
        {
            throw new Wrong(Wrong::ERR_CODE_STRING, "Second argument must be a single character");
        }

        $str_val[$i] = $char;

        $this->variables->var_set($varName, $str_val);

        return ++$count;
    }
}

class STRLEN extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int {
        $varName = $this->args[0]->get_val();
        $varType = $this->args[0]->get_type();
        $symbolArg = $this->args[1];

        parent::typeOperand($varType, VariableType::VAR);
        parent::typeOperand($symbolArg->get_type(), VariableType::SYMBOL);

        $str_val = $this->getStringValue($symbolArg);

        $this->variables->var_set($varName, strlen($str_val));

        return ++$count;
    }

    private function getStringValue(Argument $arg): string {
        $stringValue = $arg->get_val();

        if ($arg->get_type() === "var") {
            $stringValue = $this->variables->var_get($stringValue);
        }

        parent::ValueOperand($stringValue, VariableType::STRING);

        return $stringValue;
    }
}

//////////////////////////Zasobnikove instrukce//////////////////////////

// Instruction for work with stack, they was implemented in the same way as the instruction from the previous part, 
// but with the difference that they work with the stack

class AritmeticOperationS extends AritmeticOperation
{
    public function make_Vales(): void
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);

        $this->second_V = $this->getValue($this->args[1]);
        $this->first_var = $this->getValue($this->args[2]);
    }

    public function execute(int $count): int
    {
        $this->make_Vales();
        switch ($this->opcode) {
            case "ADDS":
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var + $this->second_V);
                break;
            case "SUBS":
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var - $this->second_V);
                break;
            case "MULS":
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var * $this->second_V);
                break;
            case "IDIVS":
                if ($this->second_V === 0) 
                {
                    throw new Wrong(Wrong::ERR_CODE_ZERO, "division by zero");
                }
                $this->variables->var_set($this->args[0]->get_val(), $this->first_var / $this->second_V);
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
        }

        return ++$count;
    }
}

class Logic_InstrS extends Logic_Instr
{
    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);

        $firstVar = $this->getValue($this->args[2]);
        $Second_V = null;

        if ($this->opcode !== "NOT") 
        {
            parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);
            parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
            $Second_V = $this->getValue($this->args[1]);
        }

        $res = false;
        switch ($this->opcode) 
        {
            case "ANDS":
                $res = $firstVar && $Second_V;
                break;
            case "ORS":
                $res = $firstVar || $Second_V;
                break;
            case "NOTS":
                $res = !$firstVar;
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
        }

        $this->variables->var_set($this->args[0]->get_val(), $res);
        return ++$count;
    }
}

class RelateInstrS extends RelateInstr
{
    public function execute(int $count): int 
    {
        $this->make_Var();
        switch ($this->instructionCode) {
            case "EQS":
                // Implementation
                $result = $this->nilDetected !== null ? $this->nilDetected : $this->primaryValue === $this->secondaryValue;
                $this->variableTable->var_set($this->instructionArgs[0]->get_val(), $result);
                break;
            case "GTS":
                // Implementation
                if ($this->nilDetected !== null) {
                    throw new Wrong(Wrong::ERR_CODE_TYPE, "Expected a bool value");    
                }
                $this->variableTable->var_set($this->instructionArgs[0]->get_val(), $this->primaryValue > $this->secondaryValue);
                break;
            case "LTS":
                // Implementation
                if ($this->nilDetected !== null) {
                    throw new Wrong(Wrong::ERR_CODE_TYPE, "Expected a bool value");     
                }
                $this->variableTable->var_set($this->instructionArgs[0]->get_val(), $this->primaryValue < $this->secondaryValue);
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
        }
        return ++$count;
    }
}

class INT2CHARS extends INT2CHAR
{
    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);

        $intVal = $this->getValue($this->args[1]);
        parent::ValueOperand($intVal, VariableType::INT);

        $char = chr($intVal);
        $this->variables->var_set($this->args[0]->get_val(), $char);

        return ++$count;
    }

    private function getValue(Argument $arg): int
    {
        $value = $arg->get_val();
        if ($arg->get_type() === "var") 
        {
            $value = $this->variables->var_get($value);
        }
        parent::ValueOperand($value, VariableType::INT);
        return intval($value);
    }
}

class STRI2INTS extends STR2INT
{
    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);
        parent::typeOperand($this->args[2]->get_type(), VariableType::SYMBOL);

        $string = $this->getValue($this->args[1]);
        parent::ValueOperand($string, VariableType::STRING);

        $i = $this->getValue($this->args[2]);
        parent::ValueOperand($i, VariableType::INT);
        $i = intval($i);

        if ($i < 0 || $i >= strlen($string)) {
            throw new Wrong(Wrong::ERR_CODE_STRING, "Index out of range");
        }

        $char = $string[$i];
        $this->variables->var_set($this->args[0]->get_val(), ord($char));

        return ++$count;
    }

    private function getValue(Argument $arg): mixed
    {
        $value = $arg->get_val();
        if ($arg->get_type() === "var") 
        {
            $value = $this->variables->var_get($value);
        }
        return $value;
    }
}

class JumpQ_NQ_S extends JumpQ_NQ
{
    public function execute(int $count): int 
    {
        $this->make_Value();

        switch ($this->opcode) {
            case "JUMPIFEQS":
                if ($this->areEqual) {
                    return $this->labels->label_get_index($this->args[0]->get_val()) + 1;
                }
                break;
            case "JUMPIFNEQS":
                if (!$this->areEqual) {
                    return $this->labels->label_get_index($this->args[0]->get_val()) + 1;
                }
                break;
            default:
                throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
        }

        return $count + 1; 
    }
}


////////////////////////// PODPORA FLOAT //////////////////////////

// FLOAT Podpora typu float v IPPcode24 - rozšíření

class INT2FLOAT extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) 
    {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);

        $intVal = $this->getValue($this->args[1]);
        parent::ValueOperand($intVal, VariableType::INT);

        $floatVal = floatval($intVal);
        $this->variables->var_set($this->args[0]->get_val(), $floatVal);

        return ++$count;
    }

    private function getValue(Argument $arg): int
    {
        $value = $arg->get_val();
        if ($arg->get_type() === "var") 
        {
            $value = $this->variables->var_get($value);
        }
        parent::ValueOperand($value, VariableType::INT);
        return intval($value);
    }
}

class FLOAT2INT extends Instruction_Main
{
    private For_Var $variables;
    /**
     * @var Argument[] $args
     */
    private array $args;

    /**
     * @param Argument[] $args
     */
    public function __construct(array $args, For_Var $variables) 
    {
        $this->variables = $variables;
        $this->args = $args;
    }

    public function execute(int $count): int 
    {
        parent::typeOperand($this->args[0]->get_type(), VariableType::VAR);
        parent::typeOperand($this->args[1]->get_type(), VariableType::SYMBOL);

        $floatVal = $this->getValue($this->args[1]);
        parent::ValueOperand($floatVal, VariableType::FLOAT);

        $intVal = intval($floatVal);
        $this->variables->var_set($this->args[0]->get_val(), $intVal);

        return ++$count;
    }

    private function getValue(Argument $arg): float
    {
        $value = $arg->get_val();
        if ($arg->get_type() === "var") 
        {
            $value = $this->variables->var_get($value);
        }
        parent::ValueOperand($value, VariableType::FLOAT);
        return floatval($value);
    }
}

////////////////////////// MAKE INSTRUCTION //////////////////////////

// In this part of program we will create the instructions from the XML file
// We will create the instructions based on the opcode and the arguments
// We will also check if the instructions are valid and if the arguments are valid

class Fetcher_for_XML
{
    private DOMNode $prog;
    private int $Instr_Now;
    private int $count_instr;
    /**
     * @var DOMNode[] $array_instr
     */
    private array $array_instr;

    public function __construct(DOMNode $prog = null) {
        $this->prog = $prog;
        $this->Init_List();
    }

    private function Init_List(): void {
        $instructionsXML = $this->prog->childNodes;    

        // ----- Check for the instructions
        foreach ($instructionsXML as $instructionXML) {
            if ($instructionXML->nodeType == XML_ELEMENT_NODE) {
                // Check for order attribute
                $this->array_instr[] = $instructionXML;
            }
        }

        // ----- Sort the instructions based on the order values
        //make 2 arrays, one for order values and one for instructions
        $orderValues = [];
        $instructions = [];
        // Fill the arrays
        foreach ($this->array_instr as $instruction) 
        {
            $orderValues[] = $instruction->attributes->getNamedItem("order")->nodeValue;
            $instructions[] = $instruction;
        }

        // Sort the instructions based on the order values
        array_multisort($orderValues, SORT_ASC, $instructions);
        // Update the instruction list
        $this->array_instr = $instructions;
        // Set the current instruction to the first one
        $this->count_instr = count($this->array_instr);

        //----- Check for duplicate order values
        $orders = [];
        foreach ($this->array_instr as $instruction) {
            $orderValue = intval($instruction->attributes->getNamedItem("order")->nodeValue);
            if (in_array($orderValue, $orders)) {
                throw new Wrong(Wrong::ERR_XML_SYNTAX, 'Duplicate order value found');
            }
            $orders[] = $orderValue;
        }
    }

    public function end(): bool 
    {
        return $this->Instr_Now >= $this->count_instr;
    }

    public function next_instr(int $val): void 
    {
        $this->Instr_Now = $val;
    }

    public function NextInstr_for_XML(): DOMNode
    {
        return $this->array_instr[$this->Instr_Now];
    }
}   


class Create_Instr
{
    // Function to create the arguments for the instruction
    /**
     * @return Argument[]
     * @param DOMNodeList<DOMElement> $argumentNodes
     */
    public static function createArguments($argumentNodes): array {
        $arguments = [];
        foreach ($argumentNodes as $argumentNode) {
            if ($argumentNode->nodeType == XML_ELEMENT_NODE) 
            {
                $type = $argumentNode->attributes->getNamedItem("type")->nodeValue;
                $val = $argumentNode->nodeValue;
                $arguments[] = new Argument($type, $val);
            }
        }
        return $arguments;
    }

    public static function create(
        DOMNode $instructionXML, For_Var $variables, ForLabels $labels,
        OutputWriter $output, InputReader $input
    ): Instruction_Main 
    {
        $opcode = $instructionXML->attributes->getNamedItem("opcode")->nodeValue;
        $arguments = self::createArguments($instructionXML->childNodes);
    
            switch ($opcode) 
            {
                // Work with variables
                case "DEFVAR":
                    return new DEFVAR($arguments, $variables);
                case "MOVE":
                    return new MOVE($arguments, $variables);
                // Work with frames
                case "CREATEFRAME":
                case "PUSHFRAME":
                case "POPFRAME":
                    return new Push_Creat_Pop($variables, $opcode);
                case "CALL":
                    return new CALL($arguments, $labels);
                case "RETURN":
                    return new INSTR_Return($labels);
                // work with enter/exit instructions
                case "WRITE":
                    return new WRITE($arguments, $variables, $output);
                case "READ":
                    return new READ($arguments, $variables, $input);
                // Work with aritmetic instructions
                case "ADD":
                case "SUB":
                case "MUL":
                case "IDIV":
                    return new AritmeticOperation($arguments, $variables, $opcode);
                // Work with relational instructions
                case "LT":
                case "GT":
                case "EQ":
                    return new RelateInstr($arguments, $variables, $opcode);
                // work with logical instructions
                case "AND":
                case "OR":
                case "NOT":
                    return new Logic_Instr($arguments, $variables, $opcode);
                case "INT2CHAR":
                    return new INT2CHAR($arguments, $variables);
                case "STRI2INT":
                    return new STR2INT ($arguments, $variables);
                // Work with strings
                case "CONCAT":
                    return new CONCAT($arguments, $variables);
                case "STRLEN":
                    return new STRLEN($arguments, $variables);
                case "GETCHAR":
                    return new GETCHAR($arguments, $variables);
                case "SETCHAR":
                    return new SETCHAR($arguments, $variables);
                // Work with types
                case "TYPE":
                    return new Type_Find($arguments, $variables);
                // Instrukce pro řízení toku programu
                case "JUMP":
                    return new JUMP($arguments, $labels);
                case "JUMPIFEQ":
                case "JUMPIFNEQ":
                    return new JumpQ_NQ($arguments, $variables, $labels, $opcode);
                case "EXIT":
                    return new ExitInst($arguments, $variables);
                case "LABEL":
                case "DPRINT":
                case "BREAK":
                    return new Break_Dprint_Label();
                // Zásobníkové instrukce (extension)
                case "ADDS":
                case "SUBS":
                case "MULS":
                case "IDIVS":
                    return new AritmeticOperationS($arguments, $variables, $opcode);
                case "LTS":
                case "GTS":
                case "EQS":
                    return new RelateInstrS($arguments, $variables, $opcode);
                case "ANDS":
                case "ORS":
                case "NOTS":
                    return new Logic_InstrS($arguments, $variables, $opcode);
                case "INT2CHARS":
                    return new INT2CHARS($arguments, $variables);
                case "STRI2INTS":
                    return new STRI2INTS($arguments, $variables);
                case "JUMPIFEQS":
                case "JUMPIFNEQS":
                    return new JumpQ_NQ_S($arguments, $variables, $labels, $opcode);
                // FLOAT
                case "INT2FLOAT":
                    return new INT2FLOAT($arguments, $variables);
                case "FLOAT2INT":
                    return new FLOAT2INT($arguments, $variables);
                default:
                    throw new Wrong(Wrong::ERR_XML_SYNTAX, "Unknown opcode");
            }
    }
}

class Interpreter extends AbstractInterpreter
{
    protected For_Var $vars;
    protected ForLabels $labels;
    protected DOMNode $prog_XML; //program xml Node
    protected Fetcher_for_XML $array_Instr;
    protected int $count;

    public function execute(): int
    {
        // TODO: Start your code here
        // Check \IPP\Core\AbstractInterpreter for predefined I/O objects:
        // $val = $this->input->readString();
        // $this->stdout->writeString("$val\n");
        // $this->stderr->writeString("stderr");
        // $attributes = $dom->firstChild->attributes;
        // print_r($dom);
        // $labels->loadLabels();

        try {
        
        $dom = $this->source->getDOMDocument();

        $this->prog_XML = $dom->firstChild; // xml node
        $this->vars = new For_Var; // symbol table
        $this->labels = new ForLabels; // label table
        $this->array_Instr = new Fetcher_for_XML($this->prog_XML); // array of instructions
        $this->count = 0; // program counter, index of current instruction
        
        $this->Control_Label($this->array_Instr, $this->labels);        

        for ($this->array_Instr->next_instr($this->count); !$this->array_Instr->end(); $this->array_Instr->next_instr($this->count)) {
            $nextInstructionXML = $this->array_Instr->NextInstr_for_XML();
            $this->process_for_instr($nextInstructionXML);
        }
        
        } 
        catch (IPPException $th) {
            exit($th->getCode());
        } 

        return 0;        
    }

    // Function to process the instruction
    private function process_for_instr(DOMNode $instructionXML): void {
        $instruction = Create_Instr::create(
            $instructionXML, $this->vars, $this->labels, $this->stdout, $this->input
        );

        $this->count = $instruction->execute($this->count);
    }

    // Function to process the LABEL instructions
    private function Control_Label(Fetcher_for_XML $instructions, ForLabels $labels): void {
        $count = 0;
        $instructions->next_instr($count);
    
        while (!$instructions->end()) {
            $instructionXml = $instructions->NextInstr_for_XML();
            $opcode = $instructionXml->attributes->getNamedItem("opcode")->nodeValue;
            $arguments = Create_Instr::createArguments($instructionXml->childNodes);
    
            if ($opcode === "LABEL") {
                $type = $arguments[0]->get_type();
                if ($type !== "label") {
                    throw new Wrong(Wrong::ERR_XML_SEMANTIC, "Missing label");
                }
    
                $labelName = $arguments[0]->get_val();
                $labels->_label_create($labelName, $count);
            }
    
            $instructions->next_instr(++$count);
        }
    } 
}
