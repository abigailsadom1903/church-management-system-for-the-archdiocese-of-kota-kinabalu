-- Church Information: a short history of each parish, and the page it was summarised from.
-- Summaries are paraphrased from the parish pages of the Archdiocese website (catholicadkk.org, read 5 Nov 2026)
-- and, for Kinarut, the parish's own website. Admins can edit them on the Church Information page.
--
-- Format of church_history: one or more paragraphs, then one "Year – event" line per milestone
-- (those lines are shown as a timeline).
--
-- Run on any database (safe to run again: the columns are only added when missing, and the texts are reset):
--   mysql -u root church_management_system < database/church_history.sql
USE church_management_system;

ALTER TABLE church_info
    ADD COLUMN IF NOT EXISTS church_history        TEXT         NULL AFTER church_office_hours,
    ADD COLUMN IF NOT EXISTS church_history_source VARCHAR(255) NULL AFTER church_history;

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/kota-kinabalu/', church_history =
'The parish began as the Jesselton Mission, founded in 1903 by Fr Henry van der Heyden, who secured its five-acre site that April. Fr Valentine Weber built up the mission from 1906 to 1944. His first church was blessed in 1911; a second church, blessed in 1938, was bombed in 1945, and a post-war church opened in 1949. The present cathedral was built by Fr Tobias Chi and dedicated in November 1981, marking the centenary of the Mill Hill Missionaries\' work.

1903 – Mission founded by Fr Henry van der Heyden; land secured on 9 April
1911 – First church, built by Fr Weber, blessed on 22 June
1945 – Second church (blessed 1938) destroyed by bombing
1981 – Present cathedral built by Fr Tobias Chi; dedicated on 21 November
2008 – The Diocese becomes an Archdiocese'
WHERE church_location = 'Kota Kinabalu';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/labuan/', church_history =
'A mission party sailed from Manila Bay in March 1857 and reached Labuan in April, where Msgr Cuarteron formally established the mission on 16 April 1857. After the Milanese missionaries left in 1860, Msgr Cuarteron carried on the work alone until 1879, and in 1880 Rome entrusted the mission to the Mill Hill Missionaries. The first church was built in 1932, and the present church in 1970–1971 by Fr Aloysius Tung.

1857 – Mission established by Msgr Cuarteron on 16 April
1880 – Mission entrusted to the Mill Hill Missionaries
1932 – First church built by Fr Stotter
1971 – Present church completed by Fr Aloysius Tung
2007 – 150 years of Catholicism in Labuan'
WHERE church_location = 'Labuan';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/telipok/', church_history =
'Holy Family Church was blessed by Bishop Peter Chung in November 1972. The Franciscan Sisters of the Immaculate Conception set up a community and a girls\' hostel here in 1987, and the parish hall followed in 1992. The church is served by the resident priests of St Catherine Inanam, together with Good Shepherd Manggatal.

1972 – Church blessed on 23 November by Bishop Peter Chung
1987 – FSIC community and girls\' hostel set up
1992 – Parish hall built'
WHERE church_location = 'Telipok';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/terawi/', church_history =
'The first missionary to reach the area was Fr Alexander Prenger, in June 1885. The Catholic community formally began in August 1977 under St Michael Penampang, first as a Community Council and later as a Pastoral Council. A Marian grotto was built in 1984, the present 800-seat church in 1993 and the parish hall in 1999. Archbishop John Lee made the community a parish on 1 December 2010, the same year the Shrine of Mary Mother of Hope was added.

1885 – Fr Alexander Prenger reaches the area
1977 – Catholic community begins on 3 August under St Michael Penampang
1993 – Present 800-seat church built
2010 – Becomes a parish on 1 December'
WHERE church_location = 'Terawi';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/limbahau/', church_history =
'Fr Bernard Kurz, an Austrian priest from Tyrol, bought part of Limbahau hill in 1887 and the next year built a house and the first church on its top, dedicated to Mary Queen of the Most Holy Rosary. He also began formal schooling on the church grounds, and died in 1895. His successor, Fr Aloysius Louis Goossens, put the parish on a firm footing, founded St Aloysius Convent in 1898 and went on to serve the parish for 48 years. The present church was built by Fr Aloysius Tung in 1979 and opened in July 1980.

1887 – Fr Bernard Kurz buys part of Limbahau hill
1888 – First church built on the hilltop
1898 – St Aloysius Convent founded by Fr Goossens
1923 – St Mary\'s School established
1980 – Present church opened on 12 July'
WHERE church_location = 'Limbahau';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/inobong/', church_history =
'Holy Trinity Parish was carved out of St Michael Penampang and erected on 1 October 2014, with Fr Alex Sipanul as its first parish priest. Its inaugural Mass was held here at Sacred Heart Inobong, presided over by Msgr Primus Jouil. The parish now has 13 churches, and its main church at Madsiang was under construction.

2014 – Holy Trinity Parish erected on 1 October; inaugural Mass at Sacred Heart Inobong'
WHERE church_location = 'Inobong';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/inanam/', church_history =
'The Catholic community at Inanam started in 1926. Its first school, which also hosted Sunday Mass, was built in 1947 under Fr V Schoor. The first resident priest, Fr Joseph Dapoz, arrived in 1965, built the rectory in 1966 and the first church in 1969, which was blessed in August 1973. The present church was built by Fr Alex Sipanul and opened on 9 December 1990.

1926 – Catholic community at Inanam begins
1947 – First school built under Fr V Schoor
1965 – Fr Joseph Dapoz, first resident priest
1969 – First church built; blessed in August 1973
1990 – Present church opened on 9 December'
WHERE church_location = 'Inanam';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/kota-belud/', church_history =
'The present church of St Edmund was blessed by Bishop John Lee on 18 November 1989. The parish serves Kota Belud town and its surrounding communities, organised in four zones.

1989 – Present church blessed on 18 November by Bishop John Lee'
WHERE church_location = 'Kota Belud';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/tuaran-2/', church_history =
'The first missionary to reach Tuaran was Fr Pundleider in 1883, who built a house on a hill at Kg Kusai and stayed for three years. Fr Groot arrived in 1917 and the following year obtained land at Kg Tanjong, where one building served as chapel, rectory and school. Tuaran became a parish in 1954, the year the present church was built; it was renovated in 1981.

1883 – Fr Pundleider, the first missionary, arrives
1918 – Fr Groot obtains land at Kg Tanjong
1954 – Becomes a parish; present church built
1981 – Church renovated'
WHERE church_location = 'Tuaran';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/kiulu/', church_history =
'Catechists served the Kiulu area from 1959, and in 1961 Fr Terpstra built the first church at Kg Tombongon, followed by a primary school in 1962. The San Damiano Convent of the Franciscan Sisters of the Immaculate Conception was built in 1992, and by 1996 the area had 27 chapels. Kiulu, formerly part of St John Tuaran, became a parish on 1 October 2014, with Fr Bruno Yasun as resident priest.

1961 – First church built by Fr Terpstra at Kg Tombongon
1962 – Primary school built
1992 – San Damiano Convent built
2014 – Becomes a parish on 1 October'
WHERE church_location = 'Kiulu';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/papar/', church_history =
'Papar was the first station of Fr Daniel Kilty. In 1882 Fr Bernard Pundleider joined him and opened a second station upriver at Simpudu, but the missionaries were moved to Sandakan in 1883 and both stations closed. A first church was built in 1900, and the present church of St Joseph the Worker was opened on 26 August 1987.

1882 – Fr Pundleider joins Fr Kilty and opens a station at Simpudu
1883 – Missionaries move to Sandakan; the stations close
1900 – First church built
1987 – Present church opened on 26 August
2000 – Youth centre opened on 28 May'
WHERE church_location = 'Papar';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/penampang/', church_history =
'Mill Hill missionaries led by Fr Alexander Prenger arrived in Penampang in 1886 and later moved their base to Kg Dabak, near the Moyog river. Fr Rientjes joined them in 1890, around the time the first wooden church was begun. The church was extended in 1922, and the stone church, started in the 1930s under Msgr August Wachter, was completed in 1947 after a wartime delay. It is the oldest church in Penampang district and the second oldest stone church in Sabah.

1886 – Mill Hill missionaries under Fr Alexander Prenger arrive
1890 – Fr Rientjes arrives; first wooden church begun
1922 – Church extended
1936 – Foundation stone of the stone church laid on 29 September
1947 – Stone church completed'
WHERE church_location = 'Penampang';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/dontozidon/', church_history =
'Until the 1950s the area was forest; in the 1950s and 1960s Mill Hill priests from St Michael Penampang and Sacred Heart Karamunsing brought the faith here. From 1953 the site housed several religious houses, including a bishop\'s residence, a novitiate and a convent. In 1967 Fr Willibrord Smit began a proper church just below the convent, and it was blessed and opened at Easter 1969, named at the Sisters\' request.

1953 – Religious houses begin to be built at Dontozidon
1967 – Fr Smit begins building the church
1969 – Church blessed and opened at Easter
1989 – Our Lady Queen of Peace, Kobusak, blessed on 12 November'
WHERE church_location = 'Dontozidon';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/kudat/', church_history =
'By 1900 Kudat was an outstation of Sandakan, though hard to reach. Fr Stotter made it a regular outstation from 1920, and Fr Sint became its first resident priest in 1931. The first church was built in 1934 and a second in 1958; the present church was blessed by Bishop Simon Fung on 2 December 1981. It was one of the four Holy Doors of the Archdiocese during the Jubilee Year of Mercy.

1920 – Fr Stotter makes Kudat a regular outstation
1931 – Fr Sint, first resident priest
1934 – First church built
1958 – Second church built
1981 – Present church blessed on 2 December'
WHERE church_location = 'Kudat';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/ranau/', church_history =
'The Catholic community in Ranau began in 1952 under Bundu Tuhan, and St Benedict School opened in 1963. The first church was built in 1968 and renovated in 1985, and a convent followed in 1991. The present church was built in 2000, and it was one of the Holy Doors during the Jubilee Year of Mercy.

1952 – Catholic community begins under Bundu Tuhan
1963 – St Benedict School opens
1968 – First church built
1991 – Convent built
2000 – Present church built'
WHERE church_location = 'Ranau';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/bundu-tuhan/', church_history =
'The Catholic mission at Bundu Tuhan started in 1955. Mount La Verna Convent and the Don Bosco Children\'s Home were established across the road in 1966, and the present children\'s home opened on 29 March 1998. A chapel and priest\'s house were built in 1983 and St Pius X Church in 1987. The parish is also home to the Bundu Tuhan Retreat Centre.

1955 – Bundu Tuhan mission starts
1966 – Mount La Verna Convent and Don Bosco Children\'s Home established
1983 – Chapel and priest\'s house built
1987 – St Pius X Church built'
WHERE church_location = 'Bundu Tuhan';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/likas/', church_history =
'St Simon began as an outstation chapel of Sacred Heart Cathedral and was blessed by Bishop Simon Fung in 1984. It was raised to a church in 2000 and became a parish in June 2008. It is named after St Simon the Zealot.

1984 – Chapel blessed on 14 November by Bishop Simon Fung
2000 – Raised to a church on 10 November
2008 – Becomes a parish in June'
WHERE church_location = 'Likas';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/kota-marudu/', church_history =
'Catholic work in the Tandek area began with the chapel of St Andrew Razak, built in the 1960s under Fr John Rooney. St Theresa was first built as a chapel in 1978 and became a parish in the 1980s under Fr Felix Chung. Seven outstation chapels were founded between 1974 and 2000.

1960s – First chapel in the Tandek area under Fr John Rooney
1978 – St Theresa built as a chapel
1980s – Becomes a parish under Fr Felix Chung'
WHERE church_location = 'Kota Marudu';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/tanjung-aru/', church_history =
'Stella Maris became a parish in January 1958, separated from Sacred Heart Parish Jesselton, with Fr George Lampe as its first pastor. Until the church was ready, services were held at a primary school and at La Salle. Fr Lampe built the church and priest\'s house over about five years, and the church was solemnly consecrated by Bishop James Buis on 25 May 1963.

1950 – Sisters\' convent opens in July
1958 – Becomes a parish in January under Fr George Lampe
1963 – Church consecrated on 25 May by Bishop James Buis'
WHERE church_location = 'Tanjung Aru';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/manggatal/', church_history =
'The faith reached Manggatal in 1927 through Fr Valentine Weber, and Chinese families built a first church with an atap roof on Bukit Dombung, which also served as a classroom. In 1947 Fr A Grent built a second church, a two-storey wooden building used as church and school, on a three-acre plot. Bishop James Buis renamed the church from "Kungkau" to "Good Shepherd" in 1964, and the present church was blessed by Bishop Simon Fung on 15 October 1979.

1927 – The faith reaches Manggatal through Fr Valentine Weber
1947 – Second church built by Fr A Grent
1964 – Church renamed Good Shepherd
1979 – Present church blessed on 15 October
1990 – Parish hall blessed on 8 July'
WHERE church_location = 'Manggatal';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/bukit-padang/', church_history =
'The Church of Mary Immaculate stands on a 2.2-acre plot in Luyang that the Church already owned. The idea came from Fr Thomas Sham while he was at Sacred Heart Cathedral (1987–1988), and he sought government help to build it. The building went up in 1990–1991 and was blessed by Bishop John Lee on 31 October 1993. The church is cared for by the priests of Sacred Heart Cathedral.

1991 – Structural works completed on 1 May
1993 – Church blessed on 31 October by Bishop John Lee
1999 – Grotto blessed on 8 December
2018 – Silver Jubilee celebrated
2019 – Ground broken for the church extension on 11 February'
WHERE church_location = 'Bukit Padang';

UPDATE church_info SET church_history_source = 'https://www.catholicadkk.org/kepayan/', church_history =
'The first Mass here was celebrated by Fr George Lampe at the end of the 1960s at SRK Kepayan, Police Headquarters. The first chapel, for about 200 people, was built in 1974 under Fr Willibrord Smit with local donations and support from Christian police personnel. The present church was blessed and opened by Msgr Cornelius Piong on 8 November 1990. The church is cared for by Stella Maris Tanjung Aru.

1960s – First Mass celebrated by Fr George Lampe
1974 – First chapel built under Fr Willibrord Smit
1990 – Present church blessed and opened on 8 November'
WHERE church_location = 'Kepayan';

UPDATE church_info SET church_history_source = 'https://www.staugustineparishkinarut.com/our-history/', church_history =
'The faith came to Kinarut in the 1920s through Tobias Majalang, a Kadazan from Kg Binulang, Tampasak, who was baptised after studying at St Michael School in Penampang. His house became a place of worship, and the first 21 people of Mukim Kinarut were received into the Church. After the Second World War Fr Lampe resumed his visits, and a priests\' house and a mission school, St Augustine Kinarut, were built at Kg Dompison. When the school closed in 1973, its building was turned into a church.

1920s – The faith reaches Kinarut through Tobias Majalang
1946–1947 – Fr Lampe resumes visits; priests\' house and mission school built at Kg Dompison
1955 – Legion of Mary group established
1973 – School closes; its building becomes a church'
WHERE church_location = 'Kinarut';
