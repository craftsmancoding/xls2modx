# xls2modx

This command line utility is built for migrating content into our out of MODX.

- Import XLS file as MODX resources
- Export MODX resources to XLS file
- Import WordPress XLS dump into MODX

Each command has a related mapping command which generates a .yml file.  This mapping file contains all the important
mappings which define the behavior of the command.

## map:export

Create a map file defining how you want your MODX site exported.  This .yml file will be referenced by the `export` command.

## export

Export your current MODX site to an XLS file.

## map:import

Create a map file defining how you want a give XLS file imported.  This .yml file will be referenced by the `import` command.

## import

Import an XLS file into MODX.

## map:importwp

Create a map file defining how you want a WordPress XML dump file imported into MODX.





