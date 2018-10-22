# xls2modx

This command line utility is built for migrating content stored as Excel files (`XLS`) into or out-of MODX, e.g.

- Import XLS file as MODX resources
- Export MODX resources to XLS file
- Import WordPress XLS dump into MODX

Each command has a corresponding mapping command which generates a .yml file.  This mapping file contains all the important
mappings which define the behavior of the command.

## Installation

```
git clone https://github.com/craftsmancoding/xls2modx.git
cd xls2modx
composer install
chmod +x xls2modx
```
Installation is also possible using [Repoman](https://github.com/craftsmancoding/repoman).

This project was originally conceived as a bit of licensed software... but since it has not really helped fund my mansion or yacht, I have just stripped out the license check and made it freely available.

---------------------------
# Commands

Each command can be executed from the command line using the following syntax:

```
php xls2modx <command>
```

## map:export

Create a map file defining how you want your MODX site exported: it's a preperation step.  This .yml file will be referenced by the `export` command.

## export

Export your current MODX site to an XLS file -- the behavior of this command can be configured using a .yml file generated by the `map:export` command.

## map:import

Create a map file defining how you want a given XLS file to be imported.  This .yml file will be referenced by the `import` command.

## import

Import an XLS file into MODX -- the behavior of this command can be configured using a .yml file generated by the `map:import` command.

## map:importwp

Create a map file defining how you want a WordPress XML dump file imported into MODX.

-------------------

# Examples

To export your MODx site, first create your export map:

```
php xls2modx map:export
```

Then edit the newly created `export.yml` file.  It has comments and it should give you a good starting point for seeing what all your custom fields (a.k.a. template variables) are, and you can tweak the desired names of your XLS columns.

Once you have edited the `export.yml` configuration to your liking, then you can export your MODX content to an .xls file by specifing the target filename and the .yml configuration file to be used, for example:

```
php xls2modx export /path/to/output.xls export.yml
```


## Troubleshooting

If the export command appears to finish, but there's no file created, then PHP may have silently barfed due to a memory error.  On successful runs, you should see a message at the end stating "Export complete".  If you don't see that message, try setting the `--limit` and `--offset` parameter to make for smaller XLS files, e.g. 

```
php xls2modx export --limit=1000 --offset=0 /path/to/output.xls export.yml
...
php xls2modx export --limit=1000 --offset=1000 /path/to/output.xls export.yml
...
etc
```

On larger sites or sites with lots of template variables, you may need to break up the job into several smaller exports to avoid this kind of memory error.
