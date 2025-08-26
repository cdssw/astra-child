import pandas as pd
import requests

# 1. 발급받은 REST API 키를 여기에 입력하세요.
api_key = ""

# 2. 주소가 담긴 CSV 파일 경로를 입력하세요.
csv_file_path = "camp2.csv"

# 3. 위경도 값을 추가할 새로운 CSV 파일 경로를 입력하세요.
output_csv_path = "output_camp2.csv"

# 지오코딩 함수 정의
def geocode(address):
    """
    카카오 API를 사용하여 주소를 위경도 값으로 변환하는 함수
    """
    url = "https://dapi.kakao.com/v2/local/search/address.json"
    headers = {"Authorization": f"KakaoAK {api_key}"}
    params = {"query": address}

    try:
        response = requests.get(url, headers=headers, params=params)
        response.raise_for_status() # HTTP 오류가 발생하면 예외를 발생시킵니다.
        data = response.json()

        if data['documents']:
            first_result = data['documents'][0]['address']
            longitude = first_result['x'] # 경도 (Longitude)
            latitude = first_result['y']  # 위도 (Latitude)
            return longitude, latitude
        else:
            print(f"주소 '{address}'에 대한 결과를 찾을 수 없습니다.")
            return None, None
    except requests.exceptions.RequestException as e:
        print(f"API 요청 오류 발생: {e}")
        return None, None

# CSV 파일 읽기
try:
    df = pd.read_csv(csv_file_path, encoding='utf-8')
except FileNotFoundError:
    print(f"오류: '{csv_file_path}' 파일을 찾을 수 없습니다.")
    exit()

# 위경도 컬럼 추가
df['longitude'] = None
df['latitude'] = None

# 수정된 코드: 위경도 값이 None이 아닐 때만 저장하도록 조건문 추가
for index, row in df.iterrows():
  address = row['address'] # 'address_column_name' 부분을 실제 주소 컬럼명으로 변경하세요.
  if pd.notna(address):
    try:
      longitude, latitude = geocode(address)
      # 위경도 값이 None이 아닐 경우에만 저장
      if longitude is not None and latitude is not None:
          df.loc[index, 'longitude'] = longitude
          df.loc[index, 'latitude'] = latitude
      else:
          print(f"주소 '{address}'에 대한 위경도 값을 찾을 수 없어 건너뜁니다.")
    except:
      # 새로운 CSV 파일로 저장
      df.to_csv(output_csv_path, index=False, encoding='utf-8-sig')

# 새로운 CSV 파일로 저장
df.to_csv(output_csv_path, index=False, encoding='utf-8-sig')

print(f"변환된 데이터가 '{output_csv_path}' 파일에 저장되었습니다.")