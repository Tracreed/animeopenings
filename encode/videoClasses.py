import os, re
from videoEncoder import encode, mux, extractFonts, extractSubtitles


class Type:
    def __init__(self, audioExt, videoExt, muxedExt, mime):
        self.aExt = audioExt
        self.vExt = videoExt
        self.mExt = muxedExt
        self.mime = mime

class IP:
    def __init__(self, folder):
        self.name = os.path.basename(folder)
        self.folder = folder
        self.series = []

        for f in os.listdir(folder):
            if os.path.isdir(os.path.join(folder, f)):
                self.series.append(Series(self, os.path.join(folder, f)))

        self.series = sorted(self.series, key=lambda series: series.order)

class Series:
    def __init__(self, parentIP, folder):
        self.name = os.path.basename(folder)
        self.parentIP = parentIP
        self.folder = folder

        self.order = getOrder(os.path.join(folder, "order"))

        displayNamePath = os.path.join(folder, "display_name")
        if os.path.exists(displayNamePath) and os.path.isfile(displayNamePath):
            self.displayName = readLinesFrom(displayNamePath).pop().strip()
        else:
            self.displayName = self.name

        self.videos = []
        for f in os.listdir(folder):
            if os.path.isdir(os.path.join(folder, f)):
                self.videos.append(Video(self, os.path.join(folder, f)))

        self.videos.sort()

        # Mark videos with credits when there also exists a creditless version.
        last = None
        for video in self.videos:
            if video.credits:
                last = video
            elif last:
                if video.type == last.type and video.number == last.number and video.source == last.source:
                    last.markCredited = True


    def getPHP(self):
        php = "\n\t'" + phpEscape(fromIllegalHalfwidthCharacter(self.displayName)) + "' => [\n"
        for video in sorted(self.videos):
            if video.passedQA:
                php += video.getPHP()
        return php[:-2] + "\n\t]" # Remove the last comma (and a newline)

    def hasApprovedVideos(self):
        return any(video.passedQA for video in self.videos)

class Video:
    ''' Attributes

    Set on Init
        parentSeries       Series      The series that this video is from.
        folder             string      The path to this video's directory.
        source             string      BD, DVD, WEB, TV, ...
        credits            bool        Whether or not this video has credits.
        number             string      ED1, ED2, ... possibly a, b, ...
        type               string      OP, IN, ED
        lastModifiedTime   Number      The last time one of the time_start, time_end, or video files, was modified.
        displayName        string      The name to display this video as.
        encoderOverride    string      Encoder Overrides
        url                string      The web source of this video. May contain a URL and other text.
        status             string      The status of this video. Must be "approved" for this video to be encoded.
        subtitles          string      Subtitle Attribution (also marks that there are subtitles for this video)
        timeStart          string      The time to start encoding the video at.
        timeEnd            string      The time to stop encoding the video at.
        passedQA           bool        Whether or not this video's status is "approved".
        song               Song        The song data for this video.
        localSubs          string      The path to the local subtitle file, if there is one.
                                       This is used in place of any subtitles in the video file.
        file               string      The path to the video file.
        markCredited       bool        Set by the Series that created this video if there are two identical videos
                                       that only differ by having credits or not. Changes the result of getFileName().

    Set After Encode
        encodedFileName    string      The path to the encoded file (not including file extension).

    Set After Mux
        types           [(Type,int)]   A list of tuples of a Type and the size of its encoded and muxed file.
    '''

    def __init__(self, parentSeries, folder):
        self.parentSeries = parentSeries
        self.folder = folder
        name = os.path.basename(folder).split("_")
        if len(name) == 4:
            self.source = name.pop()
            if name.pop() == "NC":
                self.credits = False
            else:
                self.credits = True
            self.number = name.pop()
            self.type = name.pop()
        else:
            print("The following video folder is using an invalid format:")
            print(folder)
            raise SystemExit()


        files = os.listdir(folder)
        self.lastModifiedTime = 0
        tempPath = ""

        # file_name, attributeName, update lastModifiedTime?
        potentialFiles = [
            ("display_name","displayName",False),
            ("encoder_override","encoderOverride",False),
            ("source","url",False),
            ("status","status",False),
            ("subtitles","subtitles",False),
            ("time_start","timeStart",True),
            ("time_end","timeEnd",True)
        ]
        for file, attribute, update in potentialFiles:
            if file in files:
                files.remove(file)
                tempPath = os.path.join(folder, file)
                setattr(self, attribute, readLinesFrom(tempPath).pop().strip())
                if update:
                    self.lastModifiedTime = compareModificationTime(tempPath, self.lastModifiedTime)
            else:
                setattr(self, attribute, "")
        if "fonts_extracted" in files: files.remove("fonts_extracted")
        if "subs_extracted" in files: files.remove("subs_extracted")

        self.status = self.status.lower()
        self.passedQA = self.status.startswith("approved")

        self.song = Song(self, files) # song_artist and song_title


        # Get local subtitle file name if there is one.
        self.localSubs = ""
        if len(files) == 2:
            if files[0].endswith(".ass"):
                self.localSubs = os.path.join(folder, files[0])
                files = [files[1]]
            elif files[1].endswith(".ass"):
                self.localSubs = os.path.join(folder, files[1])
                files = [files[0]]

        # Get video source file name.
        if len(files) == 1:
            fileName = files.pop()
            self.file = os.path.join(folder, fileName)
            self.lastModifiedTime = compareModificationTime(self.file, self.lastModifiedTime)
            self.encodedFileName = ""
            self.types = []
        elif self.passedQA:
            print("The following folder has too many files:")
            print(self.folder)
            raise SystemExit()
        else:
            self.file = ""

    def __lt__(self, other):
        types = ("OP","IN","ED") # opening, insert, ending
        diff = types.index(other.type) - types.index(self.type)
        if diff == 0:
            if self.number < other.number:
                return True
            elif self.number > other.number:
                return False
            else:
                return self.credits
        else:
            return diff > 0


    # Evaluates the encoder override line into parameters suitable to pass into videoEncoder.py
    def getEncodeParameters(self):
        try:
            params = []
            if self.encoderOverride != "":
                # h264|vp9@2pass|crf@<quality>
                split = self.encoderOverride.split("@")
                params.append("-f")
                params.append(split[0].strip().lower())
                params.append("-m")
                params.append(split[1].strip().lower())
                params.append("-q")
                params.append(split[2].strip().lower())
            return params
        except:
            print("Invalid encoder override found for " + self.getFileName())
            return []

    def encode(self, encodeDir, types):
        self.encodedFileName = encode(self, encodeDir, types)

    def mux(self, deployDir, types):
        for t in types:
            size = mux(self.encodedFileName, deployDir + os.sep + os.path.basename(self.encodedFileName), t)
            self.types.append((t,size))

    def updateFileMarker(self, file):
        mark = os.path.join(self.folder,file)
        if os.path.exists(mark) and os.path.getmtime(mark) > os.path.getmtime(self.file):
            return False
        open(mark, "a").close()
        os.utime(mark, None)
        return True

    def extractFonts(self):
        if self.updateFileMarker("fonts_extracted"):
            extractFonts(self.file)

    def extractSubtitles(self, deployDir):
        if not self.updateFileMarker("subs_extracted"): return

        subtitleFile = deployDir + os.sep + self.getFileName() + ".ass"

        if self.localSubs:
            extractSubtitles(self.localSubs, subtitleFile, None, None)
        else:
            extractSubtitles(self.file, subtitleFile, self.timeStart, self.timeEnd)

    # <series name>-<OP,ED><0,1,2,...>[<a,b,c,...>]-<C,NC>-<BD,DVD,PC,...>
    def getFileName(self):
        return toCamelCase(self.parentSeries.name) + "-" + self.type + self.number + ("C" if hasattr(self, "markCredited") else "") + "-" + ("C" if self.credits else "NC") + self.source

    def getPHP(self):
        number = phpEscape(self.number)
        while number[0] == "0":
            number = number[1:] # Remove leading zeros

        typename = ("Opening" if self.type == "OP" else ("Insert" if self.type == "IN" else "Ending"))
        php = "\t\t'" + (self.displayName or typename + " " + number + (" (with credits)" if self.credits else "")) + "' => [\n"

        php += "\t\t\t'file' => '" + phpEscape(self.getFileName()) + "',\n"
        php += "\t\t\t'mime' => [" + ",".join(type[0].mime for type in sorted(self.types, key=lambda x: x[1])) + "]"
        if self.song.hasData():
            php += ",\n"
            php += self.song.getPHP()
        if self.subtitles:
            php += ",\n\t\t\t'subtitles' => '" + self.subtitles + "'"

        php += "\n\t\t]"
        php += ",\n"
        return php

    def getCSVLine(self):
        csvString = self.status + ";"
        csvString += self.parentSeries.parentIP.name + ";"
        csvString += str(self.parentSeries.order) + ";"
        csvString += self.parentSeries.name + ";" + self.type + ";"
        csvString += self.number + ";" + self.song.title + ";" + self.song.artist + ";"
        csvString += ("Yes" if self.credits else "No") + ";"
        csvString += self.file + ";" + self.source + "\n"

        return csvString

class Song:
    artist = ""
    title = ""

    def __init__(self, parentVideo, files):
        if "song_artist" in files:
            files.remove("song_artist")
            self.artist = readLinesFrom(os.path.join(parentVideo.folder, "song_artist")).pop().strip()
        else:
            self.artist = ""

        if "song_title" in files:
            files.remove("song_title")
            self.title = readLinesFrom(os.path.join(parentVideo.folder, "song_title")).pop().strip()
        else:
            self.title = ""

    def hasData(self):
        return bool(self.artist or self.title)

    def getPHP(self):
        if not self.hasData():
            return ""
        else:
            php = "\t\t\t'song' => [\n"
            php += "\t\t\t\t'title' => '" + phpEscape(self.title) + "',\n"
            php += "\t\t\t\t'artist' => '" + phpEscape(self.artist) + "'\n"
            php += "\t\t\t]"
            return php


def readLinesFrom(file):
    if os.path.exists(file) and os.path.isfile(file):
        with open(file, "r", encoding = "UTF-8") as f:
            return [line for line in f if line]
    else:
        print("Expected file: " + file + " not found!")
        return [""]

def getOrder(file):
    orderLines = readLinesFrom(file)
    order = 0
    try:
        order = int(orderLines.pop())
    except ValueError:
        print("Error reading order, expected number got " + str(orderLines) + " from " + file)
    finally:
        return order

CAMEL_CASE_RE = re.compile("[ ＜＞：”／￥｜？＊。].")
def toCamelCase(string):
    string = CAMEL_CASE_RE.sub(lambda m: m.group(0).upper(), string).replace(" ","")
    return string[0].upper() + string[1:]

def fromIllegalHalfwidthCharacter(string):
    # Replace ugly fullwidth characters with halfwidth characters (because of file name restrictions)
    string = string.replace("＜", "<")
    string = string.replace("＞", ">")
    string = string.replace("：", ":")
    string = string.replace("”", "\"")
    string = string.replace("／", "/")
    string = string.replace("￥", "\\")
    string = string.replace("｜", "|")
    string = string.replace("？", "?")
    string = string.replace("＊", "*")
    string = string.replace("。", ".")

    return string

def phpEscape(string):
    return string.replace("'", "\\'")

def compareModificationTime(file, oldTime):
    fileTime = os.path.getmtime(file)
    return fileTime if fileTime > oldTime else oldTime
