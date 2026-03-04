# Kalix 02 MakeMappers

&nbsp;

## 02.A

Let MakeMappers.php check if the symlink myDump.php is present in the same directory. if it is not present then the user is asked to locate the script the then sylin is created

&nbsp;

## 02.B

let MakeMappers.php check if the symlink myDump.php is present in the same directory. if it is not present then the user is asked to locate the script the then sylin is created

&nbsp;

## 02.c

re-engineer MakeMappers.php : first the database is dumped to schema.xlsx and schema.json using the tool myDump. these files will be the "source of truth". when the dump is done then the schema.json is used to build/re-build the mappers ---NOTE--- the developer will edit either the schema.json or (more likely) the schema.xlsx. then he/she will use mydump to appy the changes to the database. once the modifications are applied they will call makemappers to update the schema.* files and rebuild the mappers

&nbsp;

## 02.C

&nbsp;

should we provide a default location for example in database/ ?

YES

ok, let it be default location and create now that directory (into app/, same level of mappers/ )

&nbsp;

