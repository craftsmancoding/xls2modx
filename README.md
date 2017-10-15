# xls2modx

This command line utility is built for migrating content into our out of MODX.

- Import XLS file as MODX resources
- Export MODX resources to XLS file
- Import WordPress XLS dump into MODX

Each command has a related mapping command which generates a .yml file.  This mapping file contains all the important
mappings which define the behavior of the command.

## Installation

```
git clone https://github.com/craftsmancoding/xls2modx.git
cd xls2modx
composer install
chmod +x xls2modx
```
Installation is also possible using [Repoman](https://github.com/craftsmancoding/repoman).

This project was conceived as a bit of licensed software... but since it has not really helped fund my mansion or yacht, I have just stripped out the license check.

---------------------------
# Commands

Each command can be executed from the command line using the following syntax:

```
php xls2modx <command>
```

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


## Examples

To export your MODx site, first create your export map:

```
php xls2modx map:export
```
Then edit the created `export.yml` file.  It should give you a good starting point for seeing what all your custom fields (a.k.a. template variables) are, and you can easily tweak the desired names of your XLS columns.

```
php xls2modx export /path/to/output.xls export.yml
```


## Troubleshooting

If the export command seems to finish, but there's no file created, it's possible that PHP silently barfed due to a memory error.  On successful runs, you should see a message at the end stating "Export complete".  If you don't see that message, try setting the `--limit` and `--offset` parameter to make for smaller XLS files, e.g. 

```
php xls2modx export --limit=1000 --offset=0 /path/to/output.xls export.yml
...
php xls2modx export --limit=1000 --offset=1000 /path/to/output.xls export.yml
...
etc
```

