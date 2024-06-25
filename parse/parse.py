import sys
import re
from xml.etree.ElementTree import Element, SubElement, tostring, ElementTree
from xml.dom import minidom

# Konstanty pro navratove chybove kody
PROG_OK = 0
ER_PARAM = 10 
ER_INPUT = 11 
ER_OTPUT = 12 
HEAD = 21 
CODE_UNKOWN = 22 
LEX_ER = 23 

header_count = 0
PRINT_ENABLE = False

def print_doc(text):
    if PRINT_ENABLE:
        print(f"LOG: {text}")

def call_help():
    print("NÁPOVĚDA SKRIPTU")
    print("Skript zkontroluje lexikalni a syntaktickou spravnost zdrojoveho kodu.")
    print("Pokud kod nema chyby, prepise jej na standardni vystup ve formatu XML.")
    print("Pro spusteni skriptu pouzijte nasledujici prikaz:")
    print("python3 parse.py <[vstupni soubor] >[vystupni soubor]")


# Funkce pro vytvoreni XML elementu pro operandy
def func_operands(xml, arg, pos, type):
    argEL = SubElement(xml, "arg"+str(pos), type=type)
    argEL.text = arg[pos]

def call_error(exit_text, error_number):
    sys.stderr.write(exit_text)
    sys.exit(error_number)

def arg_control(argc, argv):
    if argc > 1:
        if argc == 2 and argv[1] == "--help":
            call_help()
            sys.exit(PROG_OK)
        else:
            call_error("Chyba: SPATNE ZADANY PARAMETR\n", ER_PARAM)

def control_operands(line_elements, number, message):
    if len(line_elements) != number:
        call_error("Chyba: Spatny pocet operandu " + message + "\n", LEX_ER)

def head_control(line_elements):
    global header_count
    if len(line_elements) == 1:
        if line_elements[0].lower() != ".ippcode24":
            call_error("Chyba: Spatna hlavicka programu. Spatne zadany identifikator jazyka.\n", HEAD)
        else:
            header_count += 1
            if header_count > 1:
                call_error("Chyba: neznamy nebo chybny operacni kod ve zdrojovem kodu. \n", CODE_UNKOWN)
    else:
        call_error("Chyba: Spatna hlavicka programu. Uvodni radek musi obsahovat pouze identifikator jazyka.\n", HEAD)

# Funkce pro kontrolu promenne
def match_control_var(xml, arg, pos):
    if re.match(r"^(GF|LF|TF)@[a-zA-Z_\-$&%*!?][\w\-$&%*!?]*$", arg[pos]):
        func_operands(xml, arg, pos, "var")
    else:
        call_error("Chyba: Spatne zadany operand promenne.", LEX_ER)

def match_control_label(xml, arg, pos):
    if re.match(r"^[a-zA-Z_\-$&%*!?][\w\-$&%*!?]*$", arg[pos]):
        func_operands(xml, arg, pos, "label")
    else:
        call_error("Chyba:Spatne zadany operand navesti.", LEX_ER)

def match_control_type(xml, arg, pos):
    if re.match(r"^(int|bool|string)$", arg[pos]):
        func_operands(xml, arg, pos, "type")
    else:
        call_error("Chyba: Spatne zadany operand typu.", LEX_ER)

def control_match_const(xml, arg, pos):
    Token = arg[pos].split("@", 1)
    if len(Token) != 2:
        call_error("Chyba: Spatne zadana konstanta. Neobsahuje oddelovaci @.", LEX_ER)

    if Token[0] == "nil":
        if arg[pos] != "nil@nil":
            call_error("Chyba: Spatne zadany typ nil (polozka za @).", LEX_ER)
    elif Token[0] == "int":
        if not re.match(r"^[+-]?(([1-9][0-9]*(_[0-9]+)*|0)|(0[xX][0-9a-fA-F]+(_[0-9a-fA-F]+)*)|(0[oO]?[0-7]+(_[0-7]+)*))$", Token[1]):
            call_error("Chyba: Spatne zadany typ int (polozka za @).", LEX_ER)
    elif Token[0] == "bool":
        if not re.match(r"^bool@(true|false)$", arg[pos]):
            call_error("Chyba: Spatne zadany typ bool (polozka za @).", LEX_ER)
    elif Token[0] == "string":
        if not re.match(r"^string@((?:[^\\]|\\[0-9]{3})*)$", arg[pos]):
            call_error("Chyba: Spatne zadany typ string (polozka za @).", LEX_ER)
    else:
        call_error("Chyba: Nerozpoznany typ konstanty. Povolene typy jsou nil, bool, int, string.", LEX_ER)

    argEL = SubElement(xml, "arg"+str(pos), type=Token[0])
    argEL.text = Token[1]

def control_match_symb(xml, arg, pos):
    Token = arg[pos].split("@", 1)
    if len(Token) == 2:
        if Token[0] in ["GF", "LF", "TF"]:
            match_control_var(xml, arg, pos)
        else:
            control_match_const(xml, arg, pos)
    else:
        call_error("Chyba: Symbol neni konstanta ani promenna.", LEX_ER)

# Hlavni funkce pro zpracovani instrukci
def main_instr(vyraz, order, line_elements):
    instr_res = SubElement(vyraz, "instruction", order=str(order), opcode=line_elements[0].upper())
    
    # Kontrola argumentu
    if len(line_elements) > 1:
        opcode = line_elements[0].upper()
        if opcode in ["ADD", "SUB", "MUL", "IDIV", "LT", "GT", "EQ", "AND", "OR", "STRI2INT", "CONCAT",
                      "GETCHAR", "SETCHAR"]:
            control_operands(line_elements, 4, "Ocekavaji se 3 operandy za instrukci.")
            match_control_var(instr_res, line_elements, 1)
            control_match_symb(instr_res, line_elements, 2)
            control_match_symb(instr_res, line_elements, 3)
        elif opcode in ["CREATEFRAME", "PUSHFRAME", "POPFRAME", "RETURN", "BREAK"]:
            control_operands(line_elements, 1, "Neocekava se zadny operand.")
        elif opcode in ["NOT", "MOVE", "INT2CHAR", "STRLEN", "TYPE"]:
            control_operands(line_elements, 3, "Ocekavaji se 2 operandy.")
            match_control_var(instr_res, line_elements, 1)
            control_match_symb(instr_res, line_elements, 2)
        elif opcode in ["PUSHS", "WRITE", "EXIT", "DPRINT"]:
            control_operands(line_elements, 2, "Ocekava se 1 operand.")
            control_match_symb(instr_res, line_elements, 1)
        elif opcode in ["CALL", "LABEL", "JUMP"]:
            control_operands(line_elements, 2, "Ocekava se 1 operand.")
            match_control_label(instr_res, line_elements, 1)
        elif opcode in ["DEFVAR", "POPS"]:
            control_operands(line_elements, 2, "Ocekava se 1 operand.")
            match_control_var(instr_res, line_elements, 1)
        elif opcode in ["JUMPIFEQ", "JUMPIFNEQ"]:
            control_operands(line_elements, 4, "Ocekavaji se 3 operandy.")
            match_control_label(instr_res, line_elements, 1)
            control_match_symb(instr_res, line_elements, 2)
            control_match_symb(instr_res, line_elements, 3)
        elif opcode == "READ":
            control_operands(line_elements, 3, "Ocekavaji se 2 operandy.")
            match_control_var(instr_res, line_elements, 1)
            match_control_type(instr_res, line_elements, 2)

        # close teg instruction after all arguments
        return instr_res

# Hlavni funkce, ktera zpracuje vstupni soubor
def main_parse():
    print_doc("START SKRIPTU parse.py")

    arg_control(len(sys.argv), sys.argv)

    result = Element("program", language="IPPcode24")
    tree = ElementTree(result)

    order = 0
    header_ok = False
    lines = sys.stdin.readlines()
    if not lines:
        call_error("NEPODARILO SE PRECIST DATA\n", ER_INPUT)

    print_doc("File was read.")

    for line in lines:
        print_doc(f"RADEK {order}: {line}")
        
        line = re.sub(r"#.*", "", line)  
        line = line.strip()

        if line:
            line_elements = line.split()
            
            if not header_ok:
                head_control(line_elements)
                header_ok = True
                print_doc("Header is ok.")
            else:
                if header_count == 1 and header_ok and line_elements[0].lower() == ".ippcode24":
                    head_control(line_elements)
                else:
                    order += 1
                    main_instr(result, order, line_elements)

    # Vytiskne XML
    xml_print_str = tostring(result, encoding="utf-8", method="xml")
    xml_print_str = minidom.parseString(xml_print_str).toprettyxml(indent="    ", encoding="utf-8").decode("utf-8")
    print(xml_print_str)

    print_doc("End of parse.py")

if __name__ == "__main__":
    main_parse()
    sys.exit(PROG_OK)
