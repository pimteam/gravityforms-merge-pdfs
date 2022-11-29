Gravity Forms Merge PDFs

This plugin was created to fulfill a need in some workflows to generate a single PDF file from a Gravity Forms entry that included one or more PDF file uploads. In usage scenarios such as these, site users sometimes prefer to have a single PDF file that includes the form entry data, which may be generated using [Gravity PDF](https://gravitypdf.com/) or [Gravity Flow’s PDF Generator](https://gravityflow.io/downloads/pdf-generator/), along with each PDF file uploaded during form submission. In the original usage scenario, our forms were sometimes collecting 10 or more individual PDF uploads, and those who were tasked with reviewing the submissions wanted a single, consolidated file.

[Gennady Kovshenin](https://www.codeable.io/developers/gennady-kovshenin/), WordPress core contributor and former lead developer at [GravityKit](https://www.gravitykit.com/), created the original plugin. It received subsequent updates and enhancements from [Hassan Ali](https://hassan-ali.com/), followed by extensive updates and extended functionality from [Bob Handzhiev](https://kibokolabs.com/). Bob is now the plugin's lead developer.

The PDF merging process is handled by the [PDFMerger](https://github.com/myokyawhtun/PDFMerger) library by Jarrod Nettles.

Some Notes

    • The plugin will use GhostScript if it is installed on the server to help parse some occasionally tricky PDFs.
    • Gravity Forms is required.
    • If form data (not just PDF file uploads) is desired to be included in the merged PDF, the plugin will work with Gravity PDF or Gravity Flow’s PDF Generator for that purpose.
    • The plugin currently only handles PDF file uploads. No other file formats are supported at this time.
    • The plugin will merge the form data (if applicable) and uploaded PDF files in the order in which they appear in the form. 
    • Despite the original need to merge everything into a single PDF, the clients started asking for exceptions like all clients do. To exclude a particular PDF from being added to the merged file, use the custom CSS class skip_merge in the Gravity Forms field for that upload.
    • The plugin also integrates with PDFs uploaded through Gravity Perks’s Nested Forms.

Although not everyone needs this plugin, enough of our sites did, and given the work and expense required to create and update it, we felt it was too useful not to share with the open source community at large. Have fun!

