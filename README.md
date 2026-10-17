# CustmonSongStorage
For Geometry Dash private servers / A custom API so you can save the new music from your GDPS to Vercel.app As extra 1GB storage! 

# Utility 

To use it you must have a GitHub account or only Vercel account,
you can go To [Create New Vercel Project](https://vercel.com/new)

and you give to import project by Github Repo URL with this :
```
https://github.com/Omax64MXG4ming/CustmonSongStorage/tree/API
```
and click in deploy, After that,

you can go to `Environment Variables`

and Important, Need u to Remember or save for this, the ``music-storage.php`` keys 

and After this,

can now Create the Secrets

# Environment Variables

```
MUSIC_CALLBACK_SECRET
```
with vaule from ```NEO_MUSIC_CALLBACK_SECRET``` from the php settings ,

```
MUSIC_UPLOAD_CALLBACK_URL
```
from ```NEO_MUSIC_API_URL``` set

```
MUSIC_UPLOAD_SECRET
```
from ```NEO_MUSIC_API_SECRET``` set 

, For the Blob storage go To Your Proyect

# Setup Storage

in 3 Lines Menu, Search Storage and Enter to It,
and Click on Create Storage ,

And Click on 
``
Blob 
Fast Object Storage
``

AND Setup It with default Name 
and Click on Public Setting Blob for Accept POST and GET from Your SETUP GDPS URL 

and Click :

* Add a read-write token env var to this connection
and Click on Create,

As I already prepared the file for the blob, you no longer need to configure anything else, if this is the occasion,
And you give in your deploys and give a manual reset.



## Note 

It only works on the megasa1nt and cvolton gmdprivateservers core.
